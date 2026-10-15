<?php

declare(strict_types=1);

namespace Sentinel\Monitor\ErrorAlerts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One logged error, reduced to what an alert may carry.
 *
 * <b>Never the context.</b> The logger hands its listeners the context before
 * redacting it — passwords, tokens and whatever else a caller passed — and it
 * would also blow the 4096-byte sealed push. All that survives is the
 * channel, the level, the message and, when the context says, where it
 * happened.
 */
final class LoggedError
{
    public function __construct(
        public readonly string $level,
        public readonly string $channel,
        public readonly string $message,
        public readonly string $file = '',
        public readonly int $line = 0,
    ) {
    }

    /**
     * Build one from what the logger's hooks pass.
     *
     * @param array<mixed> $context
     */
    public static function fromLog(string $level, string $message, array $context): self
    {
        $channel = isset($context['_channel']) && is_string($context['_channel']) && $context['_channel'] !== ''
            ? $context['_channel']
            : 'default';

        $file = isset($context['file']) && is_string($context['file']) ? $context['file'] : '';
        $line = isset($context['line']) && is_int($context['line']) ? $context['line'] : 0;

        return new self(strtolower($level), $channel, $message, $file, $line);
    }

    /**
     * What makes two errors "the same one" for throttling.
     *
     * The message is the interpolated one, so the same failure carries a
     * different id, count or hash each time. Runs of digits and of hex are
     * folded first so those count as one error rather than a new one each
     * time. File and line are left out: the same message from two places is
     * still one problem to go and look at.
     */
    public function fingerprint(): string
    {
        $normalised = strtolower($this->message);
        $normalised = (string) preg_replace('/\b[0-9a-f]{6,}\b/', '#', $normalised);
        $normalised = (string) preg_replace('/\d+/', '#', $normalised);
        $normalised = trim((string) preg_replace('/\s+/', ' ', $normalised));

        return sha1($this->channel . '|' . $this->level . '|' . $normalised);
    }

    /** "file.php:123", with the path trimmed to the part worth reading. */
    public function location(): string
    {
        if ($this->file === '') {
            return '';
        }

        $file = str_replace('\\', '/', $this->file);
        $marker = strpos($file, '/wp-content/');
        if ($marker !== false) {
            $file = substr($file, $marker + strlen('/wp-content/'));
        }

        return $this->line > 0 ? $file . ':' . $this->line : $file;
    }
}
