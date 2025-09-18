<?php

namespace apaoww\cerbos;

use yii\base\Component;
use Yii;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class CerbosHttpAuth extends Component
{
    public $host = 'localhost:3592';
    public $httpHost = null; // Optional separate HTTP host
    public $plaintext = true; // For compatibility, not used in HTTP client
    public $systemPrefix = null; // System prefix for resource names
    public $projectCode = null; // Project code for multi-tenant role filtering
    public $db = 'db'; // Database component name for role queries
    public $authAssignmentTable = 'auth_assignment'; // Table name for auth assignments
    
    private $httpClient;
    
    public function init()
    {
        parent::init();

        // Use HTTP host if specified, otherwise use main host
        $baseUri = $this->httpHost ?: str_replace(':3593', ':3592', $this->host);
        if (!str_starts_with($baseUri, 'http')) {
            $baseUri = 'http://' . $baseUri;
        }
        
        $this->httpClient = new Client([
            'base_uri' => $baseUri,
            'timeout' => 5.0,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }
    
    public function checkPermission($action, $resourceType, $resourceId = null, $resourceAttributes = [])
    {
        $user = Yii::$app->user->identity;
        if (!$user) {
            Yii::info("CerbosHttpAuth: No user identity found, denying access", __METHOD__);
            return false;
        }

        Yii::info("CerbosHttpAuth: Making HTTP request to Cerbos - Action: {$action}, Resource: {$resourceType}, ID: {$resourceId}", __METHOD__);
        
        try {
            $response = $this->httpClient->post('/api/check/resources', [
                'json' => [
                    'requestId' => 'yii2-' . uniqid(),
                    'principal' => $this->buildPrincipal($user),
                    'resources' => [
                        [
                            'resource' => [
                                'kind' => $resourceType,
                                'id' => $resourceId ?: 'default',
                            ],
                            'actions' => [$action],
                        ]
                    ],
                ],
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            Yii::info("CerbosHttpAuth: Cerbos server response: " . json_encode($result), __METHOD__);
            
            // Check if the action is allowed
            if (isset($result['results']) && is_array($result['results'])) {
                foreach ($result['results'] as $item) {
                    if (isset($item['resource']) && isset($item['actions'])) {
                        // Actions is a key-value map: action_name => effect
                        if (isset($item['actions'][$action])) {
                            $allowed = $item['actions'][$action] === 'EFFECT_ALLOW';
                            Yii::info("CerbosHttpAuth: Permission result for {$action} on {$resourceType}: " . ($allowed ? 'ALLOWED' : 'DENIED'), __METHOD__);
                            return $allowed;
                        }
                    }
                }
            }
            
            Yii::info("CerbosHttpAuth: No matching action found in response, defaulting to DENY", __METHOD__);
            return false;
        } catch (RequestException $e) {
            Yii::error('Cerbos HTTP request failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
    
    public function batchCheckPermissions($actions, $resourceType, $resourceId = null, $resourceAttributes = [])
    {
        $user = Yii::$app->user->identity;
        
        if (!$user) {
            return array_fill_keys($actions, false);
        }

        try {
            $response = $this->httpClient->post('/api/check/resources', [
                'json' => [
                    'requestId' => 'yii2-batch-' . uniqid(),
                    'principal' => $this->buildPrincipal($user),
                    'resources' => [
                        [
                            'resource' => [
                                'kind' => $resourceType,
                                'id' => $resourceId ?: 'default',
                            ],
                            'actions' => $actions,
                        ]
                    ],
                ],
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            $permissions = array_fill_keys($actions, false);
            
            // Parse results
            if (isset($result['results']) && is_array($result['results'])) {
                foreach ($result['results'] as $item) {
                    if (isset($item['resource']) && isset($item['actions'])) {
                        // Actions is a key-value map: action_name => effect
                        foreach ($item['actions'] as $action => $effect) {
                            if (in_array($action, $actions)) {
                                $permissions[$action] = ($effect === 'EFFECT_ALLOW');
                            }
                        }
                    }
                }
            }
            
            return $permissions;
        } catch (RequestException $e) {
            Yii::error('Cerbos HTTP batch request failed: ' . $e->getMessage(), __METHOD__);
            return array_fill_keys($actions, false);
        }
    }
    
    private function buildPrincipal($user)
    {
        $principal = [
            'id' => (string)$user->id,
            'roles' => $this->getUserRoles($user),
        ];
        
        $attributes = [];
        
        if (isset($user->department)) {
            $attributes['department'] = $user->department;
        }
        
        if (isset($user->email)) {
            $attributes['email'] = $user->email;
        }
        
        if (isset($user->username)) {
            $attributes['username'] = $user->username;
        }
        
        if (!empty($attributes)) {
            $principal['attr'] = $attributes;
        }
        
        return $principal;
    }
    
    private function buildResource($resourceType, $resourceId, $resourceAttributes)
    {
        $instanceId = $resourceId ?: 'default';
        $instanceAttributes = [];
        
        if (!empty($resourceAttributes)) {
            $instanceAttributes = $resourceAttributes;
        }
        
        $resource = [
            'kind' => $resourceType,
            'instances' => [
                $instanceId => $instanceAttributes
            ]
        ];
        
        return $resource;
    }
    
    private function getUserRoles($user)
    {
        if (!$user) {
            return ['guest'];
        }

        $query = (new \yii\db\Query())
            ->select('item_name')
            ->from($this->authAssignmentTable)
            ->where(['user_id' => $user->username]);

        // Use specified database component
        if ($this->db !== 'db') {
            $query->db = Yii::$app->get($this->db);
        }

        // Add project_code filter if configured
        if ($this->projectCode !== null) {
            $query->andWhere(['project_code' => $this->projectCode]);
        }

        $roles = $query->column();

        return !empty($roles) ? $roles : ['user'];
    }
}