<?php

namespace Paymenter\Extensions\Servers\KeyHelp;

use App\Classes\Extension\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ServerActionNotification;

class KeyHelp extends Server
{

    private function apiRequest($method, $endpoint, $data = [])
    {
        $config = $this->config;
        $url = rtrim($config['hostname'], '/') . '/api/' . $config['api_version'] . '/' . ltrim($endpoint, '/');

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $config['api_key'],
                'Accept' => 'application/json',
            ])->$method($url, $data);

            if ($response->successful()) {
                return $response->json();
            } else {
                return ['error' => 'API request failed with status: ' . $response->status()];
            }
        } catch (\Exception $e) {
            return ['error' => 'API request failed: ' . $e->getMessage()];
        }
    }

    public function getConfig($values = []): array
    {

        $selectedLanguage = $values['extension_language'] ?? 'en';
        $translations = $this->getTranslations($selectedLanguage);

        return [
            [
                'name' => 'hostname',
                'label' => $translations['hostname'],
                'description' => $translations['hostname_description'],
                'type' => 'text',
                'friendlyName' => 'Hostname',
                'required' => true,
                'default' => 'keyhelp.example.com',
            ],
            [
                'name' => 'api_version',
                'label' => $translations['api_version'],
                'description' => $translations['api_version_description'],
                'type' => 'text',
                'friendlyName' => $translations['api_version'],
                'required' => true,
                'default' => 'v2',
            ],
            [
                'name' => 'api_key',
                'label' => $translations['api_key'],
                'description' => $translations['api_key_description'],
                'type' => 'text',
                'friendlyName' => $translations['api_key'],
                'required' => true,
            ],
            [
                'name' => 'extension_language',
                'label' => $translations['extension_language'],
                'description' => $translations['extension_language_description'],
                'type' => 'select',
                'options' => [
                    'en' => 'English',
                    'nl' => 'Dutch',
                ],
                'friendlyName' => $translations['extension_language'],
                'required' => true,
                'default' => 'en',
                'live' => true,
            ],
        ];
    }

    public function getProductConfig($values = []): array
    {

        $selectedLanguage = $this->config['extension_language'] ?? 'en';
        $translations = $this->getTranslations($selectedLanguage);

        $hostingPlans = [];
        $plans = $this->apiRequest('get', '/hosting-plans');
        if (is_array($plans)) {
            foreach ($plans as $plan) {
                if (isset($plan['id']) && isset($plan['name'])) {
                    $hostingPlans[$plan['id']] = $plan['name'];
                }
            }
        } else {
        }

        return [
            [
                'name' => 'plan',
                'label' => $translations['hosting_plan'],
                'description' => $translations['hosting_plan_description'],
                'type' => 'select',
                'options' => $hostingPlans,
                'friendlyName' => $translations['hosting_plan'],
                'required' => true,
            ],
            [
                'name' => 'default_password',
                'label' => $translations['default_password'],
                'description' => $translations['default_password_description'],
                'type' => 'text',
                'friendlyName' => $translations['default_password'],
                'required' => true,
                'default' => 'User123!!',
            ],
            [
                'name' => 'language',
                'label' => $translations['default_language'],
                'description' => $translations['default_language_description'],
                'type' => 'select',
                'options' => [
                    'id' => 'Indonesian',
                    'ca' => 'Catalan',
                    'de' => 'German',
                    'en' => 'English',
                    'es' => 'Spanish',
                    'fr' => 'French',
                    'it' => 'Italian',
                    'hu' => 'Hungarian',
                    'nl' => 'Dutch',
                    'no' => 'Norwegian',
                    'pl' => 'Polish',
                    'pt' => 'Portuguese',
                    'pt-BR' => 'Brazilian Portuguese',
                    'sv' => 'Swedish',
                    'tr' => 'Turkish',
                    'ru' => 'Russian',
                    'ar' => 'Arabic',
                    'zh-CN' => 'Simplified Chinese',
                    'zh-TW' => 'Traditional Chinese',
                ],
                'friendlyName' => 'Default Language',
                'required' => true,
                'default' => 'de',
            ],
            [
                'name' => 'comments',
                'label' => $translations['comments'],
                'description' => $translations['comments_description'],
                'type' => 'text',
                'friendlyName' => $translations['comments'],
                'required' => false,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        $response = $this->apiRequest('get', 'ping');
        return isset($response['response']) && $response['response'] === 'pong' ? true : 'Invalid API credentials';
    }

    public function createServer(Service $service, $settings, $properties)
    {
        $settings = array_merge($settings, $properties);
        $orderUser = $service->order->user;
        $userResponse = $this->apiRequest('get', '/api/clients', [
            'filter' => ['email' => $orderUser->email],
        ]);
        Log::info('User response:', ['response' => $userResponse]);

        if (isset($userResponse['data']) && count($userResponse['data']) > 0) {
            $user = $userResponse['data'][0]['id'];
        } else {
            $user = null;
        }

        if (!$user) {
            $username = strtolower($orderUser->first_name);
            $password = $settings['default_password'];
            $email = $orderUser->email;
            $language = $settings['language'] ?? 'de';
            $comments = $settings['comments'] ?? '';
            $systemdomain = false;
            $send_login_credentials = true;
            $is_suspended = false;
            $plan = $settings['plan'] ?? '';
            $contactData = [
                'first_name' => $orderUser->first_name,
                'last_name' => $orderUser->last_name,
                'company' => '',
                'telephone' => '',
                'address' => '',
                'city' => '',
                'zip' => '',
                'state' => '',
                'country' => '',
                'client_id' => $orderUser->id,
            ];
            $response = $this->apiRequest('post', '/clients', [
                'username' => $username,
                'language' => $language,
                'email' => $email,
                'password' => $password,
                'notes' => $comments,
                'id_hosting_plan' => $plan,
                'is_suspended' => $is_suspended,
                'suspend_on' => null,
                'delete_on' => null,
                'send_login_credentials' => $send_login_credentials,
                'create_system_domain' => $systemdomain,
                'contact_data' => $contactData,
            ]);

            if (isset($response['status']) && $response['status'] === 'success') {
                $clientId = $response['id'];
                $response = $this->apiRequest('post', '/clients/' . $clientId . '/resources', [
                    'plan' => $plan,
                    'create_system_domain' => $systemdomain,
                ]);
                if (isset($response['status']) && $response['status'] === 'success') {
                    $response = $this->apiRequest('put', '/clients/' . $clientId . '/resources', [
                        'plan' => $plan,
                        'create_system_domain' => $systemdomain,
                    ]);
                    if (isset($response['status']) && $response['status'] === 'success') {
                        $service->update(['username' => $username, 'password' => $password]);
                        return true;
                    }
                }
            }
            return false;
        }
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        $settings = array_merge($settings, $properties);
        $orderUser = $service->order->user;
        $userResponse = $this->apiRequest('get', '/api/clients', [
            'filter' => ['email' => $orderUser->email],
        ]);

        if (isset($userResponse['data']) && count($userResponse['data']) > 0) {
            $user = $userResponse['data'][0]['id'];
        } else {
            $user = null;
        }

        if (!$user) {
            $username = strtolower($orderUser->first_name);
            $response = $this->apiRequest('put', '/clients/name/' . $username, [
                'is_suspended' => true,
            ]);
            if (isset($response['status']) && $response['status'] === 'success') {
                return true;
            }
        }
        return false;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $settings = array_merge($settings, $properties);
        $orderUser = $service->order->user;
        $userResponse = $this->apiRequest('get', '/api/clients', [
            'filter' => ['email' => $orderUser->email],
        ]);

        if (isset($userResponse['data']) && count($userResponse['data']) > 0) {
            $user = $userResponse['data'][0]['id'];
        } else {
            $user = null;
        }

        if (!$user) {
            $username = strtolower($orderUser->first_name);
            $response = $this->apiRequest('put', '/clients/name/' . $username, [
                'is_suspended' => false,
            ]);
            if (isset($response['status']) && $response['status'] === 'success') {
                return true;
            }
        }
        return false;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $settings = array_merge($settings, $properties);
        $orderUser = $service->order->user;
        $userResponse = $this->apiRequest('get', '/api/clients', [
            'filter' => ['email' => $orderUser->email],
        ]);

        if (isset($userResponse['data']) && count($userResponse['data']) > 0) {
            $user = $userResponse['data'][0]['id'];
        } else {
            $user = null;
        }

        if (!$user) {
            $username = strtolower($orderUser->first_name);
            $response = $this->apiRequest('delete', '/clients/name/' . $username);
            if (isset($response['status']) && $response['status'] === 'success') {
                return true;
            }
        }
        return false;
    }

    private function getTranslations($extensionlanguage)
    {
        $path = __DIR__ . '/lang/' . $extensionlanguage . '/extension.php';
        if (file_exists($path)) {
            return include $path;
        }

        return include __DIR__ . '/lang/en/extension.php';
    }

    public function getActions(Service $service, $settings, $properties)
    {

        $settings = array_merge($settings, $properties);
        $orderUser = $service->order->user;
        $firstName = (string) $orderUser->first_name;
        $username = strtolower(trim($firstName));
        $defaultPassword = $settings['default_password'] ?? 'Unknown';
        return [
            [
                'type' => 'button',
                'label' => 'Login to Control Panel',
                'url' => $this->config('hostname') . '?username=' . $username,
            ],
            [
                'type' => 'button',
                'label' => 'Default Password: ' . $defaultPassword,
                'disabled' => true,
                'url' => $this->config(''),
            ],
        ];
    }
}