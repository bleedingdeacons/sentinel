<?php

declare(strict_types=1);

namespace Sentinel\Monitor\ErrorAlerts;

if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Logger\HasLogger;
use Sentinel\Monitor\Alerts\Alert;
use Sentinel\Monitor\Alerts\AlertRepository;
use Sentinel\Monitor\Alerts\AlertRequest;
use Sentinel\Monitor\Alerts\MessageUuid;
use Sentinel\Monitor\Alerts\Transport\AlertTransport;
use Sentinel\Monitor\Devices\Device;
use Sentinel\Monitor\Devices\DeviceRepository;
use WP_Error;

/**
 * Turns one logged error into alerts on the opted-in handsets.
 *
 * <b>One row per handset, addressed to that handset.</b> A broadcast would be
 * the obvious shape and the wrong one: every handset polls for broadcasts,
 * so an error stored that way would reach the whole rota by poll whatever
 * the opt-in said. Device-targeted rows reach exactly the handsets named, by
 * push and by poll alike, and share one message id so they read as one
 * message. They are informational (`response: none`): one person closing
 * their copy says nothing about anybody else's.
 *
 * <b>No Unity.</b> Everything here is built from {@see
 * \Sentinel\Monitor\MonitorServices}, and the member gate is not consulted:
 * the alert most worth sending is the one about Unity failing. The handsets
 * were gate-checked when they enrolled and are on every request they make.
 *
 * The message travels sealed to each handset's own key, but it is stored in
 * the alerts table like any other alert, and it is whatever the code that
 * logged it wrote. The context never travels; see {@see LoggedError}.
 */
final class ErrorAlertSender
{
    use HasLogger;

    public const KIND = 'log_error';
    public const SOURCE = 'sentinel';

    /**
     * A day, the most an alert may live. An error at three in the morning
     * should still be on the phone when its owner wakes up.
     */
    private const TTL_SECONDS = 86400;

    private const BODY_MAX = 1000;

    protected static function logChannel(): string
    {
        return 'monitor';
    }

    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly AlertRepository $alerts,
        private readonly AlertTransport $transport,
    ) {
    }

    /**
     * Store and push one alert for an error.
     *
     * @param int $suppressed Repeats held back since this error last paged.
     * @param string $handsetLevel Red, yellow or blue: the alert of the rule
     *     the error matched. Anything else is sent blue.
     * @return int How many handsets it was stored for. Zero when none is
     *     opted in.
     */
    public function send(LoggedError $error, int $suppressed, int $now, string $handsetLevel = Alert::LEVEL_BLUE): int
    {
        if (!in_array($handsetLevel, Alert::LEVELS, true)) {
            $handsetLevel = Alert::LEVEL_BLUE;
        }

        $targets = $this->devices->findErrorAlertTargets();
        if ($targets === []) {
            return 0;
        }

        $messageUuid = MessageUuid::generate();
        $stored = 0;
        $pushed = 0;

        foreach ($targets as $device) {
            $request = $this->requestFor($error, $suppressed, $device, $messageUuid, $handsetLevel);
            if ($request instanceof WP_Error) {
                self::logWarning('Error alert could not be built', ['reason' => $request->get_error_message()]);
                return $stored;
            }

            $alert = $this->alerts->create($request, $now);
            $stored++;

            if ($this->transport->supports($device) && $this->transport->deliver($alert, $device)) {
                $pushed++;
            }
        }

        self::logInfo('Error alert sent', [
            'channel'    => $error->channel,
            'level'      => $error->level,
            'alert'      => $handsetLevel,
            'handsets'   => $stored,
            'pushed'     => $pushed,
            'suppressed' => $suppressed,
        ]);

        return $stored;
    }

    private function requestFor(
        LoggedError $error,
        int $suppressed,
        Device $device,
        string $messageUuid,
        string $handsetLevel,
    ): AlertRequest|WP_Error {
        return AlertRequest::fromArray([
            'kind'             => self::KIND,
            'source'           => self::SOURCE,
            'title'            => sprintf('[%s] %s', $error->channel, strtoupper($error->level)),
            'body'             => $this->body($error, $suppressed),
            'reference'        => substr($error->fingerprint(), 0, 12),
            'level'            => $handsetLevel,
            'response'         => Alert::RESPONSE_NONE,
            'target_device_id' => $device->id,
            'message_uuid'     => $messageUuid,
            'ttl'              => self::TTL_SECONDS,
        ]);
    }

    private function body(LoggedError $error, int $suppressed): string
    {
        $tail = '';
        if ($error->location() !== '') {
            $tail .= "\n" . $error->location();
        }
        if ($suppressed > 0) {
            $tail .= "\n" . sprintf('+%d similar since the last alert', $suppressed);
        }

        // Bytes, not characters: AlertRequest caps the body by byte length,
        // so a cut measured in characters would lose the location off the
        // end of a message with multi-byte text in it.
        $ellipsis = '…';
        $room = self::BODY_MAX - strlen($tail);
        $message = trim($error->message);
        if (strlen($message) > $room) {
            $message = rtrim((string) mb_strcut($message, 0, max(0, $room - strlen($ellipsis)), 'UTF-8')) . $ellipsis;
        }

        return $message . $tail;
    }
}
