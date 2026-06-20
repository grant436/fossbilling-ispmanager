<?php

declare(strict_types=1);

/**
 * ISPmanager Server Manager Adapter for FOSSBilling
 *
 * @copyright ServMe IT Limited (https://www.servmeit.co.nz)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 * @link https://github.com/grant436/fossbilling-ispmanager
 */

class Server_Manager_ISPmanager extends Server_Manager
{
    // ISPmanager API base URL — constructed from host and port
    private string $apiBase;

    /**
     * Initialise the adapter — called by parent constructor
     */
    public function init(): void
    {
        $host   = $this->_config['host'] ?? $this->_config['ip'];
        $port   = $this->_config['port'] ?? '1500';
        $secure = $this->_config['secure'] ?? true;

        $scheme        = $secure ? 'https' : 'http';
        $this->apiBase = "{$scheme}://{$host}:{$port}/ispmgr";
    }

    /**
     * Returns server manager parameters shown in FOSSBilling admin
     */
    public static function getForm(): array
    {
        return [
            'label'          => 'ISPmanager 6',
            'form'           => [
                'username' => [
                    'text',
                    [
                        'label'    => 'API Username (e.g. fossbilling)',
                        'required' => true,
                    ],
                ],
                'password' => [
                    'password',
                    [
                        'label'    => 'API Password',
                        'required' => true,
                    ],
                ],
            ],
        ];
    }
    /**
     * Build an authenticated API URL
     */
    private function apiUrl(string $func, array $params = []): string
    {
        $auth = urlencode($this->_config['username']) . ':' . urlencode($this->_config['password']);

        $query = http_build_query(array_merge([
            'authinfo' => $auth,
            'out'      => 'xml',
            'func'     => $func,
        ], $params));

        return $this->apiBase . '?' . $query;
    }

    /**
     * Make an API call and return parsed XML
     */
    private function apiCall(string $func, array $params = []): \SimpleXMLElement
    {
        $url = $this->apiUrl($func, $params);

        $this->getLog()->info('ISPmanager API call: ' . $func . ' params: ' . json_encode($params));

        $client   = $this->getHttpClient();
        $response = $client->request('GET', $url, [
            'verify_peer'       => false,
            'verify_host'       => false,
        ]);

        $body = trim($response->getContent());

        $this->getLog()->info('ISPmanager API response: ' . $body);

        $xml = simplexml_load_string($body);

        if ($xml === false) {
            throw new Server_Exception('ISPmanager returned invalid XML');
        }

        if (isset($xml->error)) {
            $msg = (string) $xml->error->msg ?? (string) $xml->error;
            throw new Server_Exception('ISPmanager error: ' . $msg);
        }

        return $xml;
    }

    /**
     * Generate a valid ISPmanager username from a domain name
     * Usernames must be lowercase alphanumeric, max 16 chars
     */
    private function generateUsernameFromDomain(string $domain): string
    {
        // Strip TLD and special characters
        $parts    = explode('.', $domain);
        $username = strtolower(preg_replace('/[^a-z0-9]/', '', $parts[0]));

        // Truncate to 16 characters
        $username = substr($username, 0, 16);

        // Ensure it's not empty
        if (empty($username)) {
            $username = 'user' . rand(1000, 9999);
        }

        return $username;
    }

    /**
     * Build ISPmanager limit_* parameters from a Server_Package.
     * Omits a key entirely when the plan value is null/empty, since ISPmanager
     * treats an absent limit_* parameter as unlimited. Previously these used
     * ?? fallback defaults, which silently capped "unlimited" plans (e.g.
     * Reseller Hosting) at small hardcoded numbers instead of leaving them open.
     */
    private function buildLimitParams(Server_Package $package): array
    {
        $map = [
            'limit_webdomains' => $package->getMaxDomains(),
            'limit_ftp_users'  => $package->getMaxFtp(),
            'limit_db'         => $package->getMaxSql(),
            'limit_emails'     => $package->getMaxPop(),
            'limit_quota'      => $package->getQuota(),
        ];

        $params = [];
        foreach ($map as $key => $value) {
            if ($value !== null && $value !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }
    /**
     * Test the connection to ISPmanager
     */
    public function testConnection(): bool
    {
        $this->getLog()->info('ISPmanager testConnection: ' . $this->apiBase);

        $xml = $this->apiCall('whoami');

        $username = (string) $xml->user['name'];
        $this->getLog()->info('ISPmanager connected as: ' . $username);

        return true;
    }

    /**
     * Returns the URL for the client to log into their hosting control panel
     */
    public function getLoginUrl(?Server_Account $account = null): string
    {
        if ($account === null) {
            return $this->apiBase;
        }

        // Build a direct login URL for the customer
        $host = $this->_config['host'] ?? $this->_config['ip'];
        $port = $this->_config['port'] ?? '1500';

        return "https://{$host}:{$port}/ispmgr?username={$account->getUsername()}";
    }

    /**
     * Returns the URL for the reseller/admin to log into ISPmanager
     */
    public function getResellerLoginUrl(?Server_Account $account = null): string
    {
        $host = $this->_config['host'] ?? $this->_config['ip'];
        $port = $this->_config['port'] ?? '1500';

        return "https://{$host}:{$port}/ispmgr";
    }
    /**
     * Create a new hosting account in ISPmanager
     */
    public function createAccount(Server_Account $account): bool
    {
        $this->getLog()->info('ISPmanager createAccount: ' . $account->getDomain());

        $username = $account->getUsername();
        $password = $account->getPassword();
        $domain   = $account->getDomain();
        $package  = $account->getPackage();
        $client   = $account->getClient();

        // Generate username if not set
        if (empty($username)) {
            $username = $this->generateUsernameFromDomain($domain);
            $account->setUsername($username);
        }

        $this->getLog()->info('ISPmanager createAccount username: ' . $username);

        // Step 1: Create the user account
        $params = array_merge(
            [
                'sok'       => 'ok',
                'name'      => $username,
                'passwd'    => $password,
                'confirm'   => $password,
                'limit_ssl' => 'on',
            ],
            $this->buildLimitParams($package)
        );

        $this->apiCall('user.edit', $params);

        $this->getLog()->info('ISPmanager createAccount user created: ' . $username);

        // Step 2: Create the primary domain for the account
        $email = $client ? ($client->getEmail() ?? 'admin@' . $domain) : 'admin@' . $domain;

        $this->apiCall('site.edit', [
            'sok'       => 'ok',
            'su'        => $username,
            'site_name' => $domain,
            'php'       => 'on',
            'ssl'       => 'on',
            'email'     => $email,
            'site_ssl_cert' => 'letsencrypt',
        ]);

        $this->getLog()->info('ISPmanager createAccount domain created: ' . $domain);

        return true;
    }
    /**
     * Suspend a hosting account
     */
    public function suspendAccount(Server_Account $account): bool
    {
        $this->getLog()->info('ISPmanager suspendAccount: ' . $account->getUsername());

        $this->apiCall('user.suspend', [
            'elid' => $account->getUsername(),
        ]);

        return true;
    }

    /**
     * Unsuspend a hosting account
     */
    public function unsuspendAccount(Server_Account $account): bool
    {
        $this->getLog()->info('ISPmanager unsuspendAccount: ' . $account->getUsername());

        $this->apiCall('user.resume', [
            'elid' => $account->getUsername(),
        ]);

        return true;
    }

    /**
     * Cancel and delete a hosting account
     */
    public function cancelAccount(Server_Account $account): bool
    {
        $this->getLog()->info('ISPmanager cancelAccount: ' . $account->getUsername());

        $this->apiCall('user.delete', [
            'elid' => $account->getUsername(),
            'sok'  => 'ok',
        ]);

        return true;
    }
    /**
     * Synchronize account details from ISPmanager
     */
    public function synchronizeAccount(Server_Account $account): Server_Account
    {
        $this->getLog()->info('ISPmanager synchronizeAccount: ' . $account->getUsername());

        $xml = $this->apiCall('user', [
            'elid' => $account->getUsername(),
        ]);

        // Find the user element
        foreach ($xml->elem as $elem) {
            if ((string) $elem->name === $account->getUsername()) {
                $account->setSuspended((string) $elem->active !== 'on');

                // Sync package limits
                $package = $account->getPackage();
                $package->setMaxDomains((string) $elem->limit_webdomains);
                $package->setMaxFtp((string) $elem->limit_ftp_users);
                $package->setMaxSql((string) $elem->limit_db);
                $package->setMaxPop((string) $elem->limit_emails);
                $package->setQuota((string) $elem->quota_total);
                $account->setPackage($package);

                break;
            }
        }

        return $account;
    }

    /**
     * Change the password for a hosting account
     */
    public function changeAccountPassword(Server_Account $account, string $newPassword): bool
    {
        $this->getLog()->info('ISPmanager changeAccountPassword: ' . $account->getUsername());

        $this->apiCall('user.edit', [
            'sok'     => 'ok',
            'elid'    => $account->getUsername(),
            'passwd'  => $newPassword,
            'confirm' => $newPassword,
        ]);

        return true;
    }

    /**
     * Change the username for a hosting account
     * Note: ISPmanager does not support username changes — we log and return true
     */
    public function changeAccountUsername(Server_Account $account, string $newUsername): bool
    {
        $this->getLog()->info('ISPmanager changeAccountUsername: not supported — ' 
            . $account->getUsername() . ' -> ' . $newUsername);

        return true;
    }

    /**
     * Change the primary domain for a hosting account
     */
    public function changeAccountDomain(Server_Account $account, string $newDomain): bool
    {
        $this->getLog()->info('ISPmanager changeAccountDomain: ' . $account->getUsername() 
            . ' -> ' . $newDomain);

        $client = $account->getClient();
        $email  = $client ? ($client->getEmail() ?? 'admin@' . $newDomain) : 'admin@' . $newDomain;

        $this->apiCall('site.edit', [
            'sok'       => 'ok',
            'su'        => $account->getUsername(),
            'site_name' => $newDomain,
            'php'       => 'on',
            'ssl'       => 'on',
            'email'     => $email,
            'site_ssl_cert' => 'letsencrypt',
        ]);

        return true;
    }

    /**
     * Change the IP address for a hosting account
     * ISPmanager handles IP assignment differently — log and return true
     */
    public function changeAccountIp(Server_Account $account, string $newIp): bool
    {
        $this->getLog()->info('ISPmanager changeAccountIp: ' . $account->getUsername() 
            . ' -> ' . $newIp);

        return true;
    }

    /**
     * Change the hosting package for an account
     */
    public function changeAccountPackage(Server_Account $account, Server_Package $package): bool
    {
        $this->getLog()->info('ISPmanager changeAccountPackage: ' . $account->getUsername()
            . ' -> ' . $package->getName());

        $params = array_merge(
            [
                'sok'  => 'ok',
                'elid' => $account->getUsername(),
            ],
            $this->buildLimitParams($package)
        );

        $this->apiCall('user.edit', $params);

        return true;
    }

} // End