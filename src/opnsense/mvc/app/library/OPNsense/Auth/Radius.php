<?php
/**
 *    Copyright (C) 2015 Deciso B.V.
 *
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 *
 */

namespace OPNsense\Auth;

use OPNsense\Core\Config;

/**
 * Class Radius connector
 * @package OPNsense\Auth
 */
class Radius implements IAuthConnector
{
    /**
     * @var null radius hostname / ip
     */
    private $radiusHost = null;

    /**
     * @var null port to use for authentication
     */
    private $authPort = "1812";

    /**
     * @var null port to use for accounting
     */
    private $acctPort = "1813";

    /**
     * @var null shared secret to use for this server
     */
    private $sharedSecret = null;

    /**
     * @var string radius protocol selection
     */
    private $protocol = 'PAP';

    /**
     * @var int timeout to use
     */
    private $timeout = 10;

    /**
     * @var int maximum number of retries
     */
    private $maxRetries = 3;

    /**
     * @var null RADIUS_NAS_IDENTIFIER to use, read from config.
     */
    private $nasIdentifier = 'local';


    /**
     * set connector properties
     * @param array $config connection properties
     */
    public function setProperties($config)
    {
        // map properties to object
        $confMap = array('host' => 'radiusHost',
            'radius_secret' => 'sharedSecret',
            'radius_timeout' => 'timeout',
            'radius_auth_port' => 'authPort',
            'radius_acct_port' => 'acctPort',
            'radius_protocol' => 'protocol',
            'refid' => 'nasIdentifier'
        ) ;

        // map properties 1-on-1
        foreach ($confMap as $confSetting => $objectProperty) {
            if (!empty($config[$confSetting]) && property_exists($this, $objectProperty)) {
                $this->$objectProperty = $config[$confSetting];
            }
        }
    }

    /**
     * authenticate user against radius
     * @param $username username to authenticate
     * @param $password user password
     * @return bool authentication status
     */
    public function authenticate($username, $password)
    {
        $radius = radius_auth_open();

        $error = null;
        if (!radius_add_server(
            $radius,
            $this->radiusHost,
            $this->authPort,
            $this->sharedSecret,
            $this->timeout,
            $this->maxRetries
        )) {
            $error = radius_strerror($radius);
        } elseif (!radius_create_request($radius, RADIUS_ACCESS_REQUEST)) {
            $error = radius_strerror($radius);
        } elseif (!radius_put_string($radius, RADIUS_USER_NAME, $username)) {
            $error = radius_strerror($radius);
        } elseif (!radius_put_int($radius, RADIUS_SERVICE_TYPE, RADIUS_LOGIN)) {
            $error = radius_strerror($radius);
        } elseif (!radius_put_int($radius, RADIUS_FRAMED_PROTOCOL, RADIUS_ETHERNET)) {
            $error = radius_strerror($radius);
        } elseif (!radius_put_string($radius, RADIUS_NAS_IDENTIFIER, $this->nasIdentifier)) {
            $error = radius_strerror($radius);
        } elseif (!radius_put_int($radius, RADIUS_NAS_PORT_TYPE, RADIUS_ETHERNET)) {
            $error = radius_strerror($radius);
        } else {
            // Implement extra protocols in this section.
            switch ($this->protocol) {
                case 'PAP':
                    // do PAP authentication
                    if (!radius_put_string($radius, RADIUS_USER_PASSWORD, $password)) {
                        $error = radius_strerror($radius);
                    }
                    break;
                default:
                    syslog(LOG_ERR, 'Unsupported protocol '.$this->protocol);
                    return false;
            }
        }

        // log errors and perform actual authentication request
        if ($error != null) {
            syslog(LOG_ERR, 'RadiusError:' . radius_strerror($error));
        } else {
            $request = radius_send_request($radius);
            if (!$request) {
                syslog(LOG_ERR, 'RadiusError:' . radius_strerror($error));
            } else {
                switch($request) {
                    case RADIUS_ACCESS_ACCEPT:
                        return true;
                        break;
                    case RADIUS_ACCESS_REJECT:
                        return false;
                        break;
                    default:
                        // unexpected result, log
                        syslog(LOG_ERR, 'Radius unexpected response:' . $request);
                }
            }
        }
        return false;
    }
}