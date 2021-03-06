<?php

namespace SMTPValidateEmail;

use \SMTPValidateEmail\Exceptions\Exception as Exception;
use \SMTPValidateEmail\Exceptions\Timeout as TimeoutException;
use \SMTPValidateEmail\Exceptions\NoTimeout as NoTimeoutException;
use \SMTPValidateEmail\Exceptions\NoConnection as NoConnectionException;
use \SMTPValidateEmail\Exceptions\UnexpectedResponse as UnexpectedResponseException;
use \SMTPValidateEmail\Exceptions\NoHelo as NoHeloException;
use \SMTPValidateEmail\Exceptions\NoMailFrom as NoMailFromException;
use \SMTPValidateEmail\Exceptions\NoResponse as NoResponseException;
use \SMTPValidateEmail\Exceptions\SendFailed as SendFailedException;

class Validator
{

    public $log = [];

    /**
     * Print stuff as it happens or not
     *
     * @var bool
     */
    public $debug = false;

    /**
     * Default smtp port to connect to
     *
     * @var int
     */
    public $connect_port = 25;

    /**
     * Are "catch-all" accounts considered valid or not?
     * If not, the class checks for a "catch-all" and if it determines the box
     * has a "catch-all", sets all the emails on that domain as invalid.
     *
     * @var bool
     */
    public $catchall_is_valid = true;

    /**
     * Whether to perform the "catch-all" test or not
     *
     * @var bool
     */
    public $catchall_test = false; // Set to true to perform a catchall test

    /**
     * Being unable to communicate with the remote MTA could mean an address
     * is invalid, but it might not, depending on your use case, set the
     * value appropriately.
     *
     * @var bool
     */
    public $no_comm_is_valid = false;

    /**
     * Being unable to connect with the remote host could mean a server
     * configuration issue, but it might not, depending on your use case,
     * set the value appropriately.
     */
    public $no_conn_is_valid = false;

    /**
     * Whether "greylisted" responses are considered as valid or invalid addresses
     *
     * @var bool
     */
    public $greylisted_considered_valid = true;

    /**
     * Timeout values for various commands (in seconds) per RFC 2821
     *
     * @var array
     */
    protected $command_timeouts = [
        'connected' => 10,
        'ehlo' => 120,
        'helo' => 120,
        'tls'  => 180, // start tls
        'mail' => 300, // mail from
        'rcpt' => 300, // rcpt to,
        'rset' => 30,
        'quit' => 60,
        'noop' => 60
    ];

    const CRLF = "\r\n";

    // Some smtp response codes
    const SMTP_CONNECT_SUCCESS = 220;
    const SMTP_QUIT_SUCCESS = 221;
    const SMTP_GENERIC_SUCCESS = 250;
    const SMTP_USER_NOT_LOCAL = 251;
    const SMTP_CANNOT_VRFY = 252;

    const SMTP_SERVICE_UNAVAILABLE = 421;

    // 450 Requested mail action not taken: mailbox unavailable (e.g.,
    // mailbox busy or temporarily blocked for policy reasons)
    const SMTP_MAIL_ACTION_NOT_TAKEN = 450;
    // 451 Requested action aborted: local error in processing
    const SMTP_MAIL_ACTION_ABORTED = 451;
    // 452 Requested action not taken: insufficient system storage
    const SMTP_REQUESTED_ACTION_NOT_TAKEN = 452;

    // 500 Syntax error (may be due to a denied command)
    const SMTP_SYNTAX_ERROR = 500;
    // 502 Comment not implemented
    const SMTP_NOT_IMPLEMENTED = 502;
    // 503 Bad sequence of commands (may be due to a denied command)
    const SMTP_BAD_SEQUENCE = 503;

    // 550 Requested action not taken: mailbox unavailable (e.g., mailbox
    // not found, no access, or command rejected for policy reasons)
    const SMTP_MBOX_UNAVAILABLE = 550;

    // 554 Seen this from hotmail MTAs, in response to RSET :(
    const SMTP_TRANSACTION_FAILED = 554;

    /**
     * List of response codes considered as "greylisted"
     *
     * @var array
     */
    private $greylisted = [
        self::SMTP_MAIL_ACTION_NOT_TAKEN,
        self::SMTP_MAIL_ACTION_ABORTED,
        self::SMTP_REQUESTED_ACTION_NOT_TAKEN
    ];

    /**
     * Internal states we can be in
     *
     * @var array
     */
    private $state = [
        'helo' => false,
        'mail' => false,
        'rcpt' => false
    ];

    /**
     * Holds the socket connection resource
     *
     * @var resource
     */
    private $socket;

    /**
     * Holds all the domains we'll validate accounts on
     *
     * @var array
     */
    private $domains = [];

    /**
     * @var array
     */
    private $domains_info = [];

    /**
     * Default connect timeout for each MTA attempted (seconds)
     *
     * @var int
     */
    private $connect_timeout = 10;

    /**
     * Default sender username
     *
     * @var string
     */
    private $from_user = 'user';

    /**
     * Default sender host
     *
     * @var string
     */
    private $from_domain = 'localhost';

    /**
     * The host we're currently connected to
     *
     * @var string|null
     */
    private $host = null;

    /**
     * List of validation results
     *
     * @var array
     */
    private $results = [];

    private $users = [];

    private  $usrsDomains = [];

    private $domain = null;

    private $mail;

    /**
     * @param array|string $emails Email(s) to validate
     * @param string|null $sender Sender's email address
     */
    public function __construct($emails = [], $sender = null)
    {
        if (!empty($emails)) {
            $this->setEmails($emails);
        }
        if (null !== $sender) {
            $this->setSender($sender);
        }
    }

    /**
     * Disconnects from the SMTP server if needed to release resources
     */
    public function __destruct()
    {
        $this->disconnect(false);
    }

    public function cnSend($to, $mxs, $mxPort, $fromDomain = [], $proxy, $proxyPort,$mailRecordLog,$isMailFrom = '')
    {
        $this->results = [];
        $this->domains_info = [];
        $this->results['mailError'] = '';
        $this->clearLog();
        //fromDomain判断
        if ($fromDomain) {
            $this->from_domain = $fromDomain;
        }

        if (!empty($to)) {
            $this->setEmails($to);
        }

        if (!is_array($this->domains) || empty($this->domains)) {
            return $this->results[1];
        }

        // Query the MTAs on each domain if we have them
        foreach ($this->domains as $domain => $users) {
            $this->users = $users;
            $this->usrsDomains = $domain;
            asort($mxs);

            $this->debug('MX records (' . $domain . '): ' . print_r($mxs, true));
            $this->domains_info[$domain] = [];
            $this->domains_info[$domain]['users'] = $users;
            $this->domains_info[$domain]['mxs'] = $mxs;

            if($domain == "qq.com"){
                foreach($mxs as $host){
                    $mailResult = $this->curlGetMail($to, $host, $mxPort, $proxy, $proxyPort,$mailRecordLog,$isMailFrom);
                    if ($mailResult) {
                        return $mailResult;
                    }
                }
            }else{
                $mailResult = $this->curlGetMail($to, $mxs[rand(0,count($mxs)-1)], $mxPort, $proxy, $proxyPort,$mailRecordLog,$isMailFrom);
                if ($mailResult) {
                    return $mailResult;
                }
            }
        } // outermost foreach

        return $this->getResults();
    }

    public function curlGetMail($to, $host, $mxPort, $proxy, $proxyPort,$mailRecordLog,$isMailFrom = '') {
        $emailArr = explode('@',$to);
        // Try each host, $_weight unused in the foreach body, but array_keys() doesn't guarantee the order
        $ch = curl_init("smtp://{$host}:{$mxPort}/{$this->from_domain}");
        curl_setopt($ch, CURLOPT_PROXY, $proxy); //代理ip
        curl_setopt($ch, CURLOPT_PROXYPORT, $proxyPort); //代理端口
        curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, 1); //管道
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_MAIL_FROM, "<" . $isMailFrom . ">");
        curl_setopt($ch, CURLOPT_MAIL_RCPT, array("<" . $to . ">"));
        curl_setopt($ch, CURLOPT_PUT, 1);
        $file = $mailRecordLog . $emailArr[1] . '/' . $to . '.log';
        $op = fopen($file, "a");
        curl_setopt($ch, CURLOPT_VERBOSE, true); // Uncomment to see the transaction
        curl_setopt($ch, CURLOPT_STDERR, $op);
        fwrite($op,"##{$proxy}###\n");
        curl_exec($ch);
        curl_close($ch);
        fclose($op);

        $file_content_temp = file_get_contents($file);
        $file_content = explode("##{$proxy}###", $file_content_temp);
        $array = explode(PHP_EOL, $file_content[count($file_content)-1]);
		@unlink($file);
        foreach ($array as $k => $v) {
            if (preg_match('/too many connections/i', $v)) { ///163连接超数量
                $this->setDomainResults($this->users, $this->usrsDomains, $this->no_comm_is_valid, $v);
                return $this->getResults();
            }
            if (preg_match('/Recv failure: Connection reset by peer/', $v)) { ///proxy connect
                $this->setDomainResults($this->users, $this->usrsDomains, $this->no_comm_is_valid, $v);
                return $this->getResults();
            }
            else if (preg_match("/connect to {$proxy} port {$proxyPort} failed/", $v)) {///proxy failed
                $this->setDomainResults($this->users, $this->usrsDomains, $this->no_comm_is_valid, $v);
                return $this->getResults();
            }
            else if (preg_match("/Proxy-Connection: Keep-Alive/", $v)) {///代理连接mx记录没响应
                if (!preg_match('/ 200 /', $array[$k + 2])) {
                    $this->setDomainResults($this->users, $this->usrsDomains, $this->no_comm_is_valid, $array[$k + 2]);
                    return $this->getResults();
                }
            }
            else if (preg_match("/Connection timed out after/", $v)) {///Connection timed out
                $this->setDomainResults($this->users, $this->usrsDomains, $this->no_comm_is_valid, $v);
                return $this->getResults();
            }
            else if (preg_match('/Proxy replied OK to CONNECT request/', $v)) {///coonnect
                if (!preg_match('/^\< 220/', $array[$k + 1])) {
                    $this->setDomainResults($this->users, $this->usrsDomains, $this->no_comm_is_valid, $array[$k + 1]);
                    return $this->getResults();
                }
            }
            else if (preg_match('/^\> EHLO/', $v)) {  ///ehlo
                //ehlo 返回
                if (!preg_match('/^\< 250/', $array[$k + 1])) {
                    $this->setDomainResults($this->users, $this->usrsDomains, $this->no_comm_is_valid, $array[$k + 1]);
                    return $this->getResults();
                }
            }
            else if (preg_match('/^\> MAIL FROM/', $v)) {///mail from
                //ehlo 返回
                if (!preg_match('/^\< 250/', $array[$k - 1])) {
                    $this->setDomainResults($this->users, $this->usrsDomains, $this->no_comm_is_valid, $array[$k - 1]);
                    return $this->getResults();
                }
                //mail from 返回
                if (!preg_match('/^\< 250/', $array[$k + 1])) {
                    $this->setDomainResults($this->users, $this->usrsDomains, $this->no_comm_is_valid, $array[$k + 1]);
                    return $this->getResults();
                }
            }
            else if (preg_match('/^\> RCPT TO/', $v)) {///rcpt to
                if (preg_match('/^\< 250/', $array[$k + 1])) {
                    $address = $this->users[0] . '@' . $this->usrsDomains;
                    $this->results[$address] = $array[$k + 1];
                    $this->results['passRes'][] = $array[$k + 1];
                } else {
                    $this->setDomainResults($this->users, $this->usrsDomains, $this->no_comm_is_valid, $array[$k + 1]);
                    return $this->getResults();
                }
            }
        }

        //获取未知的错误
        if (empty($this->results)) {
            $unkownError = $mailRecordLog.'unkownError.log';
            file_put_contents($unkownError,$to.'#####'.PHP_EOL,FILE_APPEND);
        }
    }







    /**-----------------------------------------------------------------**/

    public function acceptsAnyRecipient($domain)
    {
        if (!$this->catchall_test) {
            return false;
        }

        $test = 'catch-all-test-' . time();
        $accepted = $this->rcpt($test . '@' . $domain);
        if ($accepted) {
            // Success on a non-existing address is a "catch-all"
            $this->domains_info[$domain]['catchall'] = true;
            return true;
        }

        // Log when we get disconnected while trying catchall detection
        $this->noop();
        if (!$this->connected()) {
            $this->debug('Disconnected after trying a non-existing recipient on ' . $domain);
        }

        /**
         * N.B.:
         * Disconnects are considered as a non-catch-all case this way, but
         * that might not always be the case.
         */
        return false;
    }

    /**
     * date:2018.11.12
     * Performs validation of specified email addresses.
     *
     * @param array|string $emails Emails to validate (or a single one as a string)
     * @param string|null $sender Sender email address
     * @return array List of emails and their results
     */
    public function validate($emails = [], $mxs = [], $fromDomain = [], $isMailFrom = true, $sender = null)
    {
        $this->results = [];
        $this->domains_info = [];
        $this->clearLog();
        //fromDomain判断
        if ($fromDomain) {
            $this->from_domain = $fromDomain;
        }

        if (!empty($emails)) {
            $this->setEmails($emails);
        }
        if (null !== $sender) {
            $this->setSender($sender);
        }

        if (!is_array($this->domains) || empty($this->domains)) {
            return $this->results[1];
        }

        //判断domain 域名是不是qq域名
        $repeatArr = ['qq.com'];
        if (in_array($this->domain,$repeatArr)) {
            $allMxRes = $this->validateAllMx($emails,$mxs,$isMailFrom);
            return $allMxRes;
        }

        // Query the MTAs on each domain if we have them
        foreach ($this->domains as $domain => $users) {
            $this->users = $users;
            $this->usrsDomains = $domain;
            asort($mxs);
            $this->debug('MX records (' . $domain . '): ' . print_r($mxs, true));
            $this->domains_info[$domain] = [];
            $this->domains_info[$domain]['users'] = $users;
            $this->domains_info[$domain]['mxs'] = $mxs;
            // Try each host, $_weight unused in the foreach body, but array_keys() doesn't guarantee the order
            foreach ($mxs as $host) {
                // try connecting to the remote host
                try {
                    $this->connect($host);
                    if ($this->connected()) {
                        break;
                    }
                } catch (NoConnectionException $e) {
                    // Unable to connect to host, so these addresses are invalid?
                    $this->setDomainResults($users, $domain, $this->no_conn_is_valid, $e->getMessage());
                } catch (TimeoutException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (NoResponseException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (UnexpectedResponseException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (SendFailedException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (NoTimeoutException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                }
            }
            try {
                $this->expect(self::SMTP_CONNECT_SUCCESS, $this->command_timeouts['connected']);
            } catch (Exception $e){
                $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                return $this->getResults();
            } catch (NoConnectionException $e) {
                // Unable to connect to host, so these addresses are invalid?
                $this->setDomainResults($users, $domain, $this->no_conn_is_valid, $e->getMessage());
                return $this->getResults();
            } catch (TimeoutException $e) {
                $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                return $this->getResults();
            } catch (NoResponseException $e) {
                $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                return $this->getResults();
            } catch (UnexpectedResponseException $e) {
                $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                return $this->getResults();
            } catch (SendFailedException $e) {
                $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                return $this->getResults();
            } catch (NoTimeoutException $e) {
                $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                return $this->getResults();
            }
            // Are we connected?
            if ($this->connected()) {
                try {
                    // Say helo, and continue if we can talk
                    if ($this->ehlo()) {
                        //MailFrom邮箱地址判断
                        if (empty($isMailFrom)) {
                            $emails = '';
                        }
                        // try issuing MAIL FROM
                        if (!$this->mail($emails)) {
                            // MAIL FROM not accepted, we can't talk
                            $this->setDomainResults($users, $domain, $this->no_comm_is_valid);
                        }
                        /**
                         * If we're still connected, proceed (cause we might get
                         * disconnected, or banned, or greylisted temporarily etc.)
                         * see mail() for more
                         */
                        if ($this->connected()) {
                            $this->noop();
                            // If we're still connected, try issuing rcpts
                            if ($this->connected()) {
                                $this->noop();
                                // RCPT for each user
                                foreach ($users as $user) {
                                    $address                 = $user . '@' . $domain;
                                    $this->results[$address] = $this->rcpt($address);
                                    $this->noop();
                                }
                            }
                            // Saying bye-bye if we're still connected, cause we're done here
                            if ($this->connected()) {
                                // Issue a RSET for all the things we just made the MTA do
                                $this->rset();
                                $this->disconnect();
                            }
                        }
                    }
                } catch (UnexpectedResponseException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (TimeoutException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (NoConnectionException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (NoResponseException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (SendFailedException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (NoTimeoutException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                }
            }
        } // outermost foreach
        return $this->getResults();

    }

    /**
     * date:2018.11.13
     * Performs validation of specified email addresses.
     *
     * @param array|string $emails Emails to validate (or a single one as a string)
     * @param string|null $sender Sender email address
     * @return array List of emails and their results
     */
    public function validateAllMx($emails = [], $mxs = [], $isMailFrom = true)
    {
        // Query the MTAs on each domain if we have them
        foreach ($this->domains as $domain => $users) {
            $this->users = $users;
            $this->usrsDomains = $domain;
            asort($mxs);

            $this->debug('MX records (' . $domain . '): ' . print_r($mxs, true));
            $this->domains_info[$domain] = [];
            $this->domains_info[$domain]['users'] = $users;
            $this->domains_info[$domain]['mxs'] = $mxs;

            // Try each host, $_weight unused in the foreach body, but array_keys() doesn't guarantee the order
            foreach ($mxs as $host) {
                // try connecting to the remote host
                try {
                    $this->connect($host);
                } catch (NoConnectionException $e) {
                    // Unable to connect to host, so these addresses are invalid?
                    $this->setDomainResults($users, $domain, $this->no_conn_is_valid, $e->getMessage());
                } catch (TimeoutException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (NoResponseException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (UnexpectedResponseException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (SendFailedException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                } catch (NoTimeoutException $e) {
                    $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                }

                // Are we connected?
                if ($this->connected()) {
                    try {
                        $this->expect(self::SMTP_CONNECT_SUCCESS, $this->command_timeouts['connected']);
                    } catch (Exception $e){
                        $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                        return $this->getResults();
                    } catch (NoConnectionException $e) {
                        // Unable to connect to host, so these addresses are invalid?
                        $this->setDomainResults($users, $domain, $this->no_conn_is_valid, $e->getMessage());
                        return $this->getResults();
                    return $this->getResults();
                    } catch (TimeoutException $e) {
                        $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                        return $this->getResults();
                    } catch (NoResponseException $e) {
                        $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                        return $this->getResults();
                    } catch (UnexpectedResponseException $e) {
                        $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                        return $this->getResults();
                    } catch (SendFailedException $e) {
                        $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                        return $this->getResults();
                    } catch (NoTimeoutException $e) {
                        $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                        return $this->getResults();
                    }

                    try {
                        // Say helo, and continue if we can talk
                        if ($this->ehlo()) {
                            //MailFrom邮箱地址判断
                            if (empty($isMailFrom)) {
                                $emails = '';
                            }
                            // try issuing MAIL FROM
                            if (!$this->mail($emails)) {
                                // MAIL FROM not accepted, we can't talk
                                $this->setDomainResults($users, $domain, $this->no_comm_is_valid);
                                return $this->getResults();
                            }
                            /**
                             * If we're still connected, proceed (cause we might get
                             * disconnected, or banned, or greylisted temporarily etc.)
                             * see mail() for more
                             */
                            if ($this->connected()) {
                                $this->noop();
                                // If we're still connected, try issuing rcpts
                                if ($this->connected()) {
                                    $this->noop();
                                    // RCPT for each user
                                    foreach ($users as $user) {
                                        $address                 = $user . '@' . $domain;
                                        $rcptResult = $this->rcpt($address);
                                        if ($rcptResult) {
                                            $this->results['passRes'][] = $rcptResult;
                                            $this->results[$address] = $rcptResult;
                                        }
                                        $this->noop();
                                    }
                                }
                                // Saying bye-bye if we're still connected, cause we're done here
                                if ($this->connected()) {
                                    // Issue a RSET for all the things we just made the MTA do
                                    $this->rset();
                                    $this->disconnect();
                                }
                            }
                        }
                    } catch (UnexpectedResponseException $e) {
                        $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                        return $this->getResults();
                    } catch (TimeoutException $e) {
                        $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                        return $this->getResults();
                    } catch (NoConnectionException $e) {
                        $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                        return $this->getResults();
                    } catch (NoResponseException $e) {
                        $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                        return $this->getResults();
                    } catch (SendFailedException $e) {
                        $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                        return $this->getResults();
                    } catch (NoTimeoutException $e) {
                        $this->setDomainResults($users, $domain, $this->no_comm_is_valid, $e->getMessage());
                        return $this->getResults();
                    }
                }

            }
        } // outermost foreach
        return $this->getResults();
    }

    /**
     * 校验发送邮箱的账号
     * @param $mxs
     * @param $port
     * @param $mailFrom
     * @throws NoTimeoutException
     */
    public function validateAccount($mxs, $port = 25, $mailFrom = '')
    {
        if ($port != 25) {
            $this->connect_port = $port;
        }
        if ($mailFrom) {
            $this->from_domain = $mailFrom;
        }
        $this->clearLog();
        foreach ($mxs as $host) {
            try {
                $this->connect($host);
                if ($this->connected()) {
                    break;
                }
            } catch (NoConnectionException $e) {
                return [false, $e->getMessage()];
            } catch (TimeoutException $e) {
                return [false, $e->getMessage()];
            } catch (NoResponseException $e) {
                return [false, $e->getMessage()];
            } catch (UnexpectedResponseException $e) {
                return [false, $e->getMessage()];
            }
        }

        try { ///消费掉连接的socket返回
            $this->expect(self::SMTP_CONNECT_SUCCESS, $this->command_timeouts['connected']);
        } catch (\Exception $e){
            return [false, $e->getMessage()];
        }

        if ($this->connected()) {
            try {
                $message = $this->ehlo();
                if ($this->ehlo()) {
                    // try issuing MAIL FROM
                    if (!$this->mail($mailFrom)) {
                        // MAIL FROM not accepted, we can't talk
                        return [true, '账号可用'];
                    } else {
                        return [true, '账号不可用'];
                    }
                }
            } catch (\Exception $e){
                return [false, $e->getMessage()];
            }
        } else {
            return [false, "无法连接远程mx主机"];
        }
    }


    /**
     * 校验邮局连通性
     * @param $mxs
     * @throws NoTimeoutException
     */
    public function validateDomain($mxs, $port = 25)
    {
        if ($port != 25) {
            $this->connect_port = $port;
        }
        $this->clearLog();
        foreach ($mxs as $host) {
            try {
                $this->connect($host);
                if ($this->connected()) {
                    break;
                }
            } catch (NoConnectionException $e) {
                return [false, $e->getMessage()];
            } catch (TimeoutException $e) {
                return [false, $e->getMessage()];
            } catch (NoResponseException $e) {
                return [false, $e->getMessage()];
            } catch (UnexpectedResponseException $e) {
                return [false, $e->getMessage()];
            }
        }

        try { ///消费掉连接的socket返回
            $this->expect(self::SMTP_CONNECT_SUCCESS, $this->command_timeouts['connected']);
        } catch (\Exception $e){
            return [false, $e->getMessage()];
        }

        if ($this->connected()) {
            try {
                $message = $this->ehlo();
                if ($this->ehlo()) {
                    return [true, $message];
                }
            } catch (\Exception $e){
                return [false, $e->getMessage()];
            }
        } else {
            return [false, "无法连接远程mx主机"];
        }
    }



    /**
     * Get validation results
     *
     * @param bool $include_domains_info Whether to include extra info in the results
     *
     * @return array
     */
    public function getResults($include_domains_info = true)
    {
        if ($include_domains_info) {
            $this->results['domains'] = $this->domains_info;
        } else {
            unset($this->results['domains']);
        }

        return $this->results;
    }

    /**
     * Helper to set results for all the users on a domain to a specific value
     *
     * @param array $users Users (usernames)
     * @param string $domain The domain for the users/usernames
     * @param bool $val Value to set
     *
     * @return void
     */
    private function setDomainResults(array $users, $domain, $val,$data = [])
    {
        foreach ($users as $user) {
            $this->results[$user . '@' . $domain] = $val;
            if(!empty($data)) {
                $this->results['mailError'] =  $data;
            }
        }
    }

    /**
     * Returns true if we're connected to an MTA
     *
     * @return bool
     */
    protected function connected()
    {
        return is_resource($this->socket);
    }

    /**
     * Tries to connect to the specified host on the pre-configured port.
     *
     * @param string $host Host to connect to
     *
     * @throws NoConnectionException
     * @throws NoTimeoutException
     *
     * @return void
     */
    protected function connect($host)
    {
        $remote_socket = $host . ':' . $this->connect_port;
        $errnum = 0;
        $errstr = '';
        $this->host = $remote_socket;

        // Open connection
        $this->debug('Connecting to ' . $this->host);
        // @codingStandardsIgnoreLine
        $this->socket = /** @scrutinizer ignore-unhandled */
            @stream_socket_client(
                $this->host,
                $errnum,
                $errstr,
                $this->connect_timeout,
                STREAM_CLIENT_CONNECT,
                stream_context_create([])
            );

        // Check and throw if not connected
        if (!$this->connected()) {
            $this->debug('Connect failed: ' . $errstr . ', error number: ' . $errnum . ', host: ' . $this->host);
            throw new NoConnectionException('Cannot open a connection to remote host (' . $this->host . ')');
        }

        $result = stream_set_timeout($this->socket, $this->connect_timeout);
        if (!$result) {
            throw new NoTimeoutException('Cannot set timeout');
        }

        $this->debug('Connected to ' . $this->host . ' successfully');
    }

    /**
     * Disconnects the currently connected MTA.
     *
     * @param bool $quit Whether to send QUIT command before closing the socket on our end
     *
     * @return void
     */
    protected function disconnect($quit = true)
    {
        if ($quit) {
            $this->quit();
        }

        if ($this->connected()) {
            $this->debug('Closing socket to ' . $this->host);
            fclose($this->socket);
        }

        $this->host = null;
        $this->resetState();
    }

    /**
     * Resets internal state flags to defaults
     *
     * @return void
     */
    private function resetState()
    {
        $this->state['helo'] = false;
        $this->state['mail'] = false;
        $this->state['rcpt'] = false;
    }

    /**
     * Sends `EHLO` or `HELO`, depending on what's supported by the remote host.
     *
     * @return void
     */
    protected function ehlo()
    {
        $this->send('EHLO ' . $this->from_domain);

        $result = $this->expect(self::SMTP_GENERIC_SUCCESS, $this->command_timeouts['connected']);
        return $result;
    }

    /**
     * Sends a `MAIL FROM` command which indicates the sender.
     *
     * @param string $from The "From:" address
     *
     * @throws NoHeloException
     *
     * @return bool Whether the command was accepted or not
     */
    protected function mail($from)
    {
        // Issue MAIL FROM, 5 minute timeout
        $this->send('MAIL FROM:<' . $from . '>');

        try {
            $this->expect(self::SMTP_GENERIC_SUCCESS, $this->command_timeouts['mail']);

            // Set state flags
            $this->state['mail'] = true;
            $this->state['rcpt'] = false;

            $result = true;
        } catch (UnexpectedResponseException $e) {
            $result = false;

            // Got something unexpected in response to MAIL FROM
            $this->debug("Unexpected response to MAIL FROM\n:" . $e->getMessage());

            // Hotmail has been known to do this + was closing the connection
            // forcibly on their end, so we're killing the socket here too
            $this->setDomainResults($this->users, $this->usrsDomains, $this->no_comm_is_valid, $e->getMessage());
            $this->disconnect(false);
        }

        return $result;
    }

    /**
     * Sends a RCPT TO command to indicate a recipient.
     *
     * @param string $to Recipient's email address
     * @throws NoMailFromException
     *
     * @return bool Whether the recipient was accepted or not
     */
    protected function rcpt($to)
    {
        // Need to have issued MAIL FROM first
        if (!$this->state['mail']) {
            throw new NoMailFromException('Need MAIL FROM before RCPT TO');
        }

        $result = false;
        $expected_codes = [
            self::SMTP_GENERIC_SUCCESS,
            self::SMTP_USER_NOT_LOCAL
        ];

        if ($this->greylisted_considered_valid) {
            $expected_codes = array_merge($expected_codes, $this->greylisted);
        }
        // Issue RCPT TO, 5 minute timeout

        $this->send('RCPT TO:<' . $to . '>');
        // Handle response
        $result = $this->expect($expected_codes, $this->command_timeouts['rcpt']);
        if (empty($result)) {
            $result = false;
        }
        $this->state['rcpt'] = true;

        return $result;
    }

    /**
     * Sends a RSET command and resets certain parts of internal state.
     *
     * @return void
     */
    protected function rset()
    {
        $this->send('RSET');

        // MS ESMTP doesn't follow RFC according to ZF tracker, see [ZF-1377]
        $expected = [
            self::SMTP_GENERIC_SUCCESS,
            self::SMTP_CONNECT_SUCCESS,
            self::SMTP_NOT_IMPLEMENTED,
            // hotmail returns this o_O
            self::SMTP_TRANSACTION_FAILED
        ];
        $this->expect($expected, $this->command_timeouts['rset'], true);
        $this->state['mail'] = false;
        $this->state['rcpt'] = false;
    }

    /**
     * Sends a QUIT command.
     *
     * @return void
     */
    protected function quit()
    {
        // Although RFC says QUIT can be issued at any time, we won't
        if ($this->state['helo']) {
            $this->send('QUIT');
            $this->expect(
                [self::SMTP_GENERIC_SUCCESS, self::SMTP_QUIT_SUCCESS],
                $this->command_timeouts['quit'],
                true
            );
        }
    }

    /**
     * Sends a NOOP command.
     *
     * @return void
     */
    protected function noop()
    {
        $this->send('NOOP');

        /**
         * The `SMTP` string is here to fix issues with some bad RFC implementations.
         * Found at least 1 server replying to NOOP without any code.
         */
        $expected_codes = [
            'SMTP',
            self::SMTP_BAD_SEQUENCE,
            self::SMTP_NOT_IMPLEMENTED,
            self::SMTP_GENERIC_SUCCESS,
            self::SMTP_SYNTAX_ERROR,
            self::SMTP_CONNECT_SUCCESS
        ];
        $this->expect($expected_codes, $this->command_timeouts['noop'], true);
    }

    /**
     * Sends a command to the remote host.
     *
     * @param string $cmd The command to send
     *
     * @return int|bool Number of bytes written to the stream
     * @throws NoConnectionException
     * @throws SendFailedException
     */
    protected function send($cmd)
    {
        // Must be connected
        $this->throwIfNotConnected();

        $this->debug('send>>>: ' . $cmd);
        // Write the cmd to the connection stream
        $result = fwrite($this->socket, $cmd . self::CRLF);

        // Did it work?
        if (false === $result) {
            throw new SendFailedException('Send failed on: ' . $this->host);
        }

        return $result;
    }

    /**
     * Receives a response line from the remote host.
     *
     * @param int $timeout Timeout in seconds
     *
     * @return string
     *
     * @throws NoConnectionException
     * @throws TimeoutException
     * @throws NoResponseException
     */
    protected function recv($timeout = null)
    {
        // Must be connected
        $this->throwIfNotConnected();

        // Has a custom timeout been specified?
        if (null !== $timeout) {
            stream_set_timeout($this->socket, $timeout);
        }

        // Retrieve response
        $line = fgets($this->socket, 1024);
        $this->debug('<<<recv: ' . $line);

        // Have we timed out?
        $info = stream_get_meta_data($this->socket);
        if (!empty($info['timed_out'])) {
            throw new TimeoutException('Timed out in recv');
        }

        // Did we actually receive anything?
        if (false === $line) {
            throw new NoResponseException('No response in recv');
        }

        return $line;
    }

    /**
     * Receives lines from the remote host and looks for expected response codes.
     *
     * @param int|int[] $codes List of one or more expected response codes
     * @param int $timeout The timeout for this individual command, if any
     * @param bool $empty_response_allowed When true, empty responses are allowed
     *
     * @return string The last text message received
     *
     * @throws UnexpectedResponseException
     */
    protected function expect($codes, $timeout = null, $empty_response_allowed = false)
    {
        if (!is_array($codes)) {
            $codes = (array)$codes;
        }

        $code = null;
        $text = '';

        try {
            $line = $this->recv($timeout);
            $text = $line;
            while (preg_match('/^[0-9]+-/', $line)) {
                $line = $this->recv($timeout);
                $text .= $line;
            }
            sscanf($line, '%d%s', $code, $msg);
            // TODO/FIXME: This is terrible to read/comprehend
            if ($code == self::SMTP_SERVICE_UNAVAILABLE ||
                (false === $empty_response_allowed && (null === $code || !in_array($code, $codes)))) {
                throw new UnexpectedResponseException($line);
            }
        } catch (NoResponseException $e) {
            /**
             * No response in expect() probably means that the remote server
             * forcibly closed the connection so lets clean up on our end as well?
             */
            $this->debug('No response in expect(): ' . $e->getMessage());
            $this->disconnect(false);
            $this->setDomainResults($this->users, $this->usrsDomains, $this->no_comm_is_valid, $e->getMessage());
        }

        return $text;
    }

    /**
     * Splits the email address string into its respective user and domain parts
     * and returns those as an array.
     *
     * @param string $email Email address
     *
     * @return array ['user', 'domain']
     */
    protected function splitEmail($email)
    {
        $parts = explode('@', $email);
        $domain = array_pop($parts);
        $this->domain = $domain;
        $user = implode('@', $parts);

        return [$user, $domain];
    }

    /**
     * Sets the email addresses that should be validated.
     *
     * @param array|string $emails List of email addresses (or a single one a string).
     *
     * @return void
     */
    public function setEmails($emails)
    {
        if (!is_array($emails)) {
            $emails = (array)$emails;
        }

        $this->domains = [];

        foreach ($emails as $email) {
            list($user, $domain) = $this->splitEmail($email);
            if (!isset($this->domains[$domain])) {
                $this->domains[$domain] = [];
            }
            $this->domains[$domain][] = $user;
        }
    }

    /**
     * Sets the email address to use as the sender/validator.
     *
     * @param string $email
     *
     * @return void
     */
    public function setSender($email)
    {
        $parts = $this->splitEmail($email);
        $this->from_user = $parts[0];
        $this->from_domain = $parts[1];
    }

    /**
     * Throws if not currently connected.
     *
     * @return void
     * @throws NoConnectionException
     */
    private function throwIfNotConnected()
    {
        if (!$this->connected()) {
            throw new NoConnectionException('No connection');
        }
    }

    /**
     * Debug helper. If it detects a CLI env, it just dumps given `$str` on a
     * new line, otherwise it prints stuff <pre>.
     *
     * @param string $str
     *
     * @return void
     */
    private function debug($str)
    {
        $str = $this->stamp($str);
        $this->log($str);
        if ($this->debug) {
            if ('cli' !== PHP_SAPI) {
                $str = '<br/><pre>' . htmlspecialchars($str) . '</pre>';
            }
            echo "\n" . $str;
        }
    }

    /**
     * Adds a message to the log array
     *
     * @param string $msg
     *
     * @return void
     */
    private function log($msg)
    {
        $this->log[] = $msg;
    }

    /**
     * Prepends the given $msg with the current date and time inside square brackets.
     *
     * @param string $msg
     *
     * @return string
     */
    private function stamp($msg)
    {
        $date = \DateTime::createFromFormat('U.u', sprintf('%.f', microtime(true)))->format('Y-m-d\TH:i:s.uO');
        $line = '[' . $date . '] ' . $msg;

        return $line;
    }

    /**
     * Returns the log array
     *
     * @return array
     */
    public function getLog()
    {
        return $this->log;
    }

    /**
     * Truncates the log array
     *
     * @return void
     */
    public function clearLog()
    {
        $this->log = [];
    }

    /**
     * Compat for old lower_cased method calls.
     *
     * @param string $name
     * @param array $args
     *
     * @return void
     */
    public function __call($name, $args)
    {
        $camelized = self::camelize($name);
        if (\method_exists($this, $camelized)) {
            return \call_user_func_array([$this, $camelized], $args);
        } else {
            trigger_error('Fatal error: Call to undefined method ' . self::class . '::' . $name . '()', E_USER_ERROR);
        }
    }

    /**
     * Set the desired connect timeout.
     *
     * @param int $timeout Connect timeout in seconds
     *
     * @return void
     */
    public function setConnectTimeout($timeout)
    {
        $this->connect_timeout = (int)$timeout;
    }

    /**
     * Get the current connect timeout.
     *
     * @return int
     */
    public function getConnectTimeout()
    {
        return $this->connect_timeout;
    }

    /**
     * Set connect port.
     *
     * @param int $port
     *
     * @return void
     */
    public function setConnectPort($port)
    {
        $this->connect_port = (int)$port;
    }

    /**
     * Get current connect port.
     *
     * @return int
     */
    public function getConnectPort()
    {
        return $this->connect_port;
    }

    /**
     * Turn on "catch-all" detection.
     *
     * @return void
     */
    public function enableCatchAllTest()
    {
        $this->catchall_test = true;
    }

    /**
     * Turn off "catch-all" detection.
     *
     * @return void
     */
    public function disableCatchAllTest()
    {
        $this->catchall_test = false;
    }

    /**
     * Returns whether "catch-all" test is to be performed or not.
     *
     * @return bool
     */
    public function isCatchAllEnabled()
    {
        return $this->catchall_test;
    }

    /**
     * Set whether "catch-all" results are considered valid or not.
     *
     * @param bool $flag When true, "catch-all" accounts are considered valid
     *
     * @return void
     */
    public function setCatchAllValidity($flag)
    {
        $this->catchall_is_valid = (bool)$flag;
    }

    /**
     * Get current state of "catch-all" validity flag.
     *
     * @return void
     */
    public function getCatchAllValidity()
    {
        return $this->catchall_is_valid;
    }

    /**
     * Camelizes a string.
     *
     * @param string $id A string to camelize
     *
     * @return string The camelized string
     */
    private static function camelize($id)
    {
        return strtr(
            ucwords(
                strtr(
                    $id,
                    ['_' => ' ', '.' => '_ ', '\\' => '_ ']
                )
            ),
            [' ' => '']
        );
    }

}
