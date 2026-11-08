<?php
declare(strict_types=1);
namespace Plugins\notifications\mail;

use App\Plugins\Notifications\{NotificationChannelInterface, NotificationMessage, NotificationResult};
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

final class MailDriver implements NotificationChannelInterface
{
    public function getChannelId(): string { return 'mail'; }
    public function getName(): string { return 'SMTP 邮件'; }
    public function getConfigSchema(): array
    {
        return [
            'host'=>['required','string','max:253','regex:/^[a-zA-Z0-9.-]+$/D'],
            'port'=>['required','integer','between:1,65535'],
            'security'=>['required',app()->environment('test') ? 'in:smtps,starttls,test_plain' : 'in:smtps,starttls'],
            'username'=>['present','nullable','string','max:255','not_regex:/[\x00-\x1f\x7f]/'],
            'password'=>['present','nullable','string','max:4096'],
            'from'=>['required','email:rfc','max:100','not_regex:/[\x00-\x1f\x7f]/'],
            'from_name'=>['required','string','max:100','not_regex:/[\x00-\x1f\x7f]/'],
        ];
    }
    public function send(string $target, NotificationMessage $message, array $config): NotificationResult
    {
        // Also validate at execution: deployment environments/configuration can change after installation.
        if (\Illuminate\Support\Facades\Validator::make($config, $this->getConfigSchema())->fails()
            || ($config['security']==='test_plain' && $config['host']!=='127.0.0.1')) {
            return new NotificationResult('rejected','SMTP_CONFIG_INVALID');
        }
        $transport = null;
        try {
            $email=(new Email())->from(new Address($config['from'],$config['from_name']))->to($target)->subject($message->subject)->text($message->text);
            // Stable Message-ID is a diagnostic identifier, NOT proof of remote deduplication.
            $domain=substr(strrchr($config['from'],'@'),1);
            $email->getHeaders()->addIdHeader('Message-ID', hash('sha256',$message->fingerprint.'|mail').'@'.$domain);
            $transport=new EsmtpTransport($config['host'],(int)$config['port'],$config['security']==='smtps');
            $transport->setRequireTls($config['security']!=='test_plain');
            $transport->setAutoTls($config['security']!=='test_plain');
            $transport->setUsername($config['username']??'')->setPassword($config['password']??'');
            $transport->getStream()->setTimeout(10);
            // A fresh SMTP transport for exactly one recipient/message. No failover/retry wrapper.
            $transport->send($email);
            return new NotificationResult('accepted','SMTP_ACCEPTED');
        } catch (UnexpectedResponseException $e) {
            return new NotificationResult($e->getCode()>=400 && $e->getCode()<=599 ? 'rejected' : 'unknown','SMTP_RESPONSE_ERROR');
        } catch (\Throwable) {
            // Never expose SMTP transcripts, addresses, credentials or exception messages.
            return new NotificationResult('unknown','SMTP_OUTCOME_UNKNOWN');
        } finally {
            try { $transport?->stop(); } catch (\Throwable) { /* QUIT failure cannot undo a DATA acceptance. */ }
        }
    }
}
