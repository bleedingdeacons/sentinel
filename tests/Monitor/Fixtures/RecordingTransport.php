<?php

declare(strict_types=1);

namespace Sentinel\Tests\Monitor\Fixtures;

use Sentinel\Monitor\Alerts\Alert;
use Sentinel\Monitor\Alerts\Transport\AlertTransport;
use Sentinel\Monitor\Devices\Device;

/**
 * A transport that records what it was asked to deliver and accepts it,
 * so a test can see which handsets an alert was pushed to without FCM.
 */
final class RecordingTransport implements AlertTransport
{
    /** @var array<int, array{alert: Alert, device: Device}> */
    public array $delivered = [];

    public bool $accept = true;

    public function supports(Device $device): bool
    {
        return $device->wantsPush();
    }

    public function deliver(Alert $alert, Device $device): bool
    {
        $this->delivered[] = ['alert' => $alert, 'device' => $device];

        return $this->accept;
    }
}
