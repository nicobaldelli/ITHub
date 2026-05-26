<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Wrapper sobre PHPMailer.
 *
 * - Lee config_app primero (runtime, editable desde el admin) y cae a
 *   settings/.env como default
 * - Soporta multiples destinatarios + cc desde config
 * - Lanza excepción si el envío falla; el caller decide qué hacer (loguear en
 *   notificaciones_enviadas con ok=false)
 */
final class MailerService
{
    /** @var array<string,mixed> */
    private readonly array $defaultCfg;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
        $settings = $container->get('settings');
        $this->defaultCfg = array_merge($settings['smtp'] ?? [], [
            'cc_emails' => $settings['notifications']['cc_emails'] ?? [],
        ]);
    }

    /**
     * @param string[] $to
     * @param string[] $cc
     * @throws MailerException
     */
    public function send(array $to, string $subject, string $htmlBody, array $cc = [], ?string $altBody = null): void
    {
        $cfg = $this->resolveCfg();

        if (empty($cfg['host']) || empty($cfg['from'])) {
            throw new MailerException(
                'SMTP no configurado (faltan smtp_host o smtp_from en config_app / .env)'
            );
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->Port = (int) $cfg['port'];
        $mail->CharSet = 'UTF-8';

        if (!empty($cfg['user'])) {
            $mail->SMTPAuth = true;
            $mail->Username = $cfg['user'];
            $mail->Password = $cfg['pass'] ?? '';
        }

        $encryption = strtolower((string) ($cfg['encryption'] ?? 'tls'));
        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($cfg['from'], $cfg['from_name'] ?? 'ITHub');

        foreach ($to as $addr) {
            if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($addr);
            }
        }
        $ccCombined = array_merge($cc, $this->parseEmailList($cfg['cc_emails'] ?? []));
        foreach (array_unique($ccCombined) as $ccAddr) {
            if (filter_var($ccAddr, FILTER_VALIDATE_EMAIL)) {
                $mail->addCC($ccAddr);
            }
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $altBody ?? strip_tags($htmlBody);

        $mail->send();

        $this->logger->info('mail.sent', [
            'to' => $to,
            'cc' => $ccCombined,
            'subject' => $subject,
        ]);
    }

    /**
     * Merge: config_app (runtime) > settings (env) > defaults.
     * Las claves runtime en config_app empiezan con `smtp_` o `notif_cc_emails`.
     * @return array<string,mixed>
     */
    private function resolveCfg(): array
    {
        $cfg = $this->defaultCfg;
        try {
            // Lectura sin Eloquent para no requerir boot completo en CLI
            $rows = \ITHub\Api\Models\ConfigApp::query()
                ->whereIn('clave', [
                    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass',
                    'smtp_from', 'smtp_from_name', 'smtp_encryption',
                    'notif_cc_emails',
                ])
                ->get();
            foreach ($rows as $r) {
                $key = $r->clave;
                $val = $r->value; // ya casteado por el modelo
                if ($val === null || $val === '') continue;
                if (str_starts_with($key, 'smtp_')) {
                    $cfg[substr($key, 5)] = $val;
                } elseif ($key === 'notif_cc_emails') {
                    $cfg['cc_emails'] = $val;
                }
            }
        } catch (\Throwable $e) {
            // Si falla DB (CLI sin boot), seguimos con defaults
            $this->logger->warning('mail.cfg.fallback', ['error' => $e->getMessage()]);
        }
        return $cfg;
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    private function parseEmailList(mixed $raw): array
    {
        if (is_array($raw)) return $raw;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return $decoded;
            // Formato "a@b.com, c@d.com"
            return array_filter(array_map('trim', explode(',', $raw)));
        }
        return [];
    }
}
