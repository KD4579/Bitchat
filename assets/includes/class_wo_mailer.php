<?php
/**
 * PHPMailer 6.1.3 Q-encodes every header longer than ~63 chars, which turns
 * "List-Unsubscribe: <https://...>, <mailto:...>" into an encoded-word that
 * Gmail/Yahoo cannot parse, so one-click unsubscribe silently breaks.
 * Leave plain-ASCII URL-list headers as they are (RFC 5322 allows up to 998 chars).
 */
class Wo_Mailer extends \PHPMailer\PHPMailer\PHPMailer {
    public function encodeHeader($str, $position = 'text') {
        if ($position === 'text' && strlen($str) <= 900
            && preg_match('/^<(https?:|mailto:)[\x21-\x7E]+>(, ?<(https?:|mailto:)[\x21-\x7E]+>)*$/', $str)) {
            return $str;
        }
        return parent::encodeHeader($str, $position);
    }
}
