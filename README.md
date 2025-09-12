# Yii2 Cerbos Extension

A Yii2 extension that provides seamless integration with [Cerbos](https://cerbos.dev/) for fine-grained access control and authorization.

## Features

- **CerbosAccessControl**: Action filter for automatic route-based access control
- **CerbosHttpAuth**: HTTP client component for Cerbos API integration
- **System Prefix Support**: Add system-wide prefixes to resource names
- **RBAC Fallback**: Optional fallback to Yii2's built-in RBAC system
- **Flexible Resource Mapping**: Custom resource and action mapping
- **Batch Permission Checking**: Check multiple permissions in a single request

## Installation

### Via Composer (Recommended)

```bash
composer require apaoww/yii2-cerbos
```

### Local Development

Add to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "vendor/apaoww/yii2-cerbos"
        }
    ],
    "require": {
        "apaoww/yii2-cerbos": "*"
    }
}
```

## Configuration

### 1. Configure the Cerbos HTTP Component

Add the Cerbos component to your application config:

```php
// config/main.php or config/main-local.php
return [
    'components' => [
        'cerbos' => [
            'class' => 'apaoww\cerbos\CerbosHttpAuth',
            'host' => 'localhost:3592', // Your Cerbos server
            'httpHost' => null, // Optional separate HTTP host
            'systemPrefix' => 'imap', // Optional system prefix for resources
        ],
    ],
];
```

### 2. Configure Access Control

You can use CerbosAccessControl in two ways: **globally for the entire application** (recommended) or **per-controller**.

#### Option A: Global Application-Wide Access Control (Recommended)

Configure access control for your entire application by adding it to your main config file:

```php
// frontend/config/main-local.php (or backend/config/main-local.php)
return [
    'components' => [
        // ... other components
    ],
    
    // Global access control filter
    'as access' => [
        'class' => 'apaoww\cerbos\CerbosAccessControl',
        'systemPrefix' => 'imap', // System prefix for all resources
        'except' => ['debug/*', 'gii/*'], // Exclude debug and development tools
        'allowActions' => [
            // Public actions that don't require authentication
            'site/index',
            'site/login',
            'site/logout',
            'site/error',
            'site/captcha',
            'cas/*', // CAS authentication routes
        ],
        
        // Map specific routes to custom Cerbos resources and actions
        'resourceMap' => [
            'site/index' => [
                'resource' => 'dashboard',
                'action' => 'view',
            ],
            'user/*' => [
                'resource' => 'user',
                'action' => function() {
                    // Dynamic action mapping based on controller action
                    $actionId = Yii::$app->controller->action->id;
                    $actionMap = [
                        'index' => 'list',
                        'view' => 'read',
                        'create' => 'create',
                        'update' => 'update',
                        'delete' => 'delete',
                    ];
                    return $actionMap[$actionId] ?? $actionId;
                },
            ],
            'report/*' => [
                'resource' => 'report',
                'action' => function() {
                    return Yii::$app->controller->action->id;
                },
                'resourceId' => function() {
                    return Yii::$app->request->get('id');
                },
                'attributes' => [
                    'department' => function() {
                        return Yii::$app->user->identity->department ?? 'unknown';
                    },
                ],
            ],
        ],
        
        // Enable fallback to existing RBAC system during migration
        'useMdmFallback' => true,
        
        // Custom extractors for resource identification
        'resourceIdExtractor' => function($action) {
            return Yii::$app->request->get('id') ?: Yii::$app->request->post('id');
        },
        
        'resourceAttributesExtractor' => function($action) {
            $attributes = [];
            $user = Yii::$app->user->identity;
            
            if ($user) {
                if (isset($user->department)) {
                    $attributes['department'] = $user->department;
                }
                if (isset($user->role)) {
                    $attributes['role'] = $user->role;
                }
            }
            
            return $attributes;
        },
    ],
];
```

#### Option B: Per-Controller Access Control

Add the access control filter to individual controller behaviors:

```php
use apaoww\cerbos\CerbosAccessControl;

class ExampleController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => CerbosAccessControl::class,
                'systemPrefix' => 'imap', // Optional: override system prefix
                'useMdmFallback' => true, // Enable RBAC fallback
                'allowActions' => ['login', 'signup'], // Always allowed actions
                'resourceMap' => [
                    // Custom resource mappings
                    'example/special' => [
                        'resource' => 'special_resource',
                        'action' => 'custom_action',
                    ],
                ],
            ],
        ];
    }
}
```

#### When to Use Which Approach:

- **Global Configuration**: Use when you want consistent access control across your entire application. This is the recommended approach for most applications.
- **Per-Controller Configuration**: Use when you need different access control rules for specific controllers or when gradually migrating to Cerbos.

## Usage Examples

### Basic Usage

The extension automatically maps controller/action routes to Cerbos resources:

- `example/index` → Resource: `imap_example`, Action: `index`
- `example/view` → Resource: `imap_example`, Action: `read`
- `example/create` → Resource: `imap_example`, Action: `create`
- `example/update` → Resource: `imap_example`, Action: `update`
- `example/delete` → Resource: `imap_example`, Action: `delete`

### Custom Resource Mapping

```php
'resourceMap' => [
    'user/*' => [
        'resource' => 'user_management',
        'action' => function() {
            return Yii::$app->controller->action->id === 'index' ? 'list' : 'manage';
        },
    ],
    'report/generate' => [
        'resource' => 'reports',
        'action' => 'generate',
        'resourceId' => function() {
            return Yii::$app->request->get('type', 'default');
        },
        'attributes' => [
            'department' => Yii::$app->user->identity->department ?? 'unknown',
        ],
    ],
],
```

### Resource ID and Attributes Extraction

```php
'resourceIdExtractor' => function($action) {
    return Yii::$app->request->get('id') ?: 'default';
},
'resourceAttributesExtractor' => function($action) {
    return [
        'owner_id' => Yii::$app->user->id,
        'created_at' => date('Y-m-d'),
    ];
},
```

### Direct Cerbos API Usage

```php
// Single permission check
$allowed = Yii::$app->cerbos->checkPermission(
    'read', 
    'imap_document', 
    '123', 
    ['department' => 'sales']
);

// Batch permission check
$permissions = Yii::$app->cerbos->batchCheckPermissions(
    ['read', 'write', 'delete'],
    'imap_document',
    '123'
);
// Returns: ['read' => true, 'write' => false, 'delete' => false]
```

## Configuration Options

### CerbosAccessControl Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `allowActions` | array | `[]` | Actions that are always allowed without checking permissions |
| `resourceMap` | array | `[]` | Custom mapping of routes to Cerbos resources and actions |
| `useMdmFallback` | bool | `true` | Enable fallback to Yii2 RBAC when Cerbos policy doesn't exist |
| `systemPrefix` | string | `null` | Prefix to prepend to all resource names |
| `resourceIdExtractor` | callable | `null` | Custom function to extract resource ID from request |
| `resourceAttributesExtractor` | callable | `null` | Custom function to extract resource attributes |

### CerbosHttpAuth Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `host` | string | `'localhost:3592'` | Cerbos server host and port |
| `httpHost` | string | `null` | Alternative HTTP host (if different from main host) |
| `systemPrefix` | string | `null` | System prefix for resource names |

## Cerbos Policy Example

Here's an example Cerbos policy file (`imap_example.yaml`) that works with this extension:

```yaml
---
apiVersion: api.cerbos.dev/v1
resourcePolicy:
  version: "default"
  resource: "imap_example"
  rules:
    - actions: ['*']
      effect: EFFECT_ALLOW
      roles:
        - admin

    - actions: ['index', 'read']
      effect: EFFECT_ALLOW
      roles:
        - user
        - manager

    - actions: ['create', 'update', 'delete']
      effect: EFFECT_ALLOW
      roles:
        - manager
      condition:
        match:
          expr: request.resource.attr.department == principal.attr.department
```

## Requirements

- PHP 8.0 or higher
- Yii2 framework 2.0.14 or higher
- Cerbos server running and accessible
- GuzzleHTTP 7.0 or higher

## License

This extension is released under the MIT License. See LICENSE file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

If you encounter any issues or have questions, please create an issue in the GitHub repository.