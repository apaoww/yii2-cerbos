<?php

namespace apaoww\cerbos;

use Yii;
use yii\base\ActionFilter;
use yii\web\ForbiddenHttpException;
use yii\web\User;
use yii\base\Module;

class CerbosAccessControl extends ActionFilter
{
    /**
     * @var array List of actions that are always allowed
     */
    public $allowActions = [];

    /**
     * @var array Mapping of controller/action to Cerbos resource/action
     * Format: ['controller/action' => ['resource' => 'resource_type', 'action' => 'permission']]
     */
    public $resourceMap = [];

    /**
     * @var bool If true, uses mdmsoft RBAC as fallback when Cerbos policy doesn't exist
     */
    public $useMdmFallback = true;

    /**
     * @var callable|null Custom function to extract resource ID from request
     */
    public $resourceIdExtractor = null;

    /**
     * @var callable|null Custom function to extract resource attributes from request
     */
    public $resourceAttributesExtractor = null;

    /**
     * @var string|null System prefix to prepend to all resource names
     */
    public $systemPrefix = null;

    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        $user = Yii::$app->user;
        $route = $this->getActionRoute($action);

        // Check if action is in allowActions list
        if ($this->isAllowedAction($route)) {
            return true;
        }

        // If user is guest and action requires authentication
        if ($user->isGuest) {
            $user->loginRequired();
            return false;
        }

        // Check permission using Cerbos
        Yii::info("CerbosAccessControl: Checking permission for route: {$route}", __METHOD__);
        $cerbosResult = $this->checkCerbosPermission($route, $action);
        Yii::info("CerbosAccessControl: Cerbos result for {$route}: " . ($cerbosResult ? 'ALLOWED' : 'DENIED'), __METHOD__);
        
        if ($cerbosResult) {
            return true;
        }

        // Fallback to mdmsoft RBAC if enabled
        if ($this->useMdmFallback && $this->checkMdmPermission($route)) {
            return true;
        }
        $this->denyAccess($user);
        return false;
    }

    /**
     * Check if the action is allowed for everyone
     */
    protected function isAllowedAction($route)
    {
        foreach ($this->allowActions as $pattern) {
            if ($this->matchPattern($pattern, $route)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check permission using Cerbos
     */
    protected function checkCerbosPermission($route, $action)
    {
        // Get resource mapping for this route
        $mapping = $this->getResourceMapping($route);

        if (!$mapping) {
            // If no explicit mapping, create default mapping
            $mapping = $this->createDefaultMapping($route);
        }

        try {
            $resourceId = null;
            $resourceAttributes = [];

            // Extract resource ID if extractor is provided
            if ($this->resourceIdExtractor !== null) {
                $resourceId = call_user_func($this->resourceIdExtractor, $action);
            } elseif (isset($mapping['resourceId'])) {
                $resourceId = is_callable($mapping['resourceId'])
                    ? call_user_func($mapping['resourceId'])
                    : $mapping['resourceId'];
            } else {
                $resourceId = Yii::$app->request->get('id');
            }

            // Extract resource attributes if extractor is provided
            if ($this->resourceAttributesExtractor !== null) {
                $resourceAttributes = call_user_func($this->resourceAttributesExtractor, $action);
            } elseif (isset($mapping['attributes'])) {
                $resourceAttributes = is_callable($mapping['attributes'])
                    ? call_user_func($mapping['attributes'])
                    : $mapping['attributes'];
            }

            // Execute action function if it's callable
            if (isset($mapping['action']) && is_callable($mapping['action'])) {
                $mapping['action'] = call_user_func($mapping['action']);
            }

            // Apply system prefix to resource name if configured
            $resourceName = $mapping['resource'];
            if ($this->systemPrefix) {
                $resourceName = $this->systemPrefix . '_' . $resourceName;
            }

            Yii::info("CerbosAccessControl: Calling Cerbos API - Resource: {$resourceName}, Action: {$mapping['action']}, ResourceId: {$resourceId}", __METHOD__);
            
            $result = Yii::$app->cerbos->checkPermission(
                $mapping['action'],
                $resourceName,
                $resourceId,
                $resourceAttributes
            );
            
            Yii::info("CerbosAccessControl: Cerbos API returned: " . ($result ? 'ALLOW' : 'DENY'), __METHOD__);
            return $result;
        } catch (\Exception $e) {
            Yii::error("Cerbos permission check failed: " . $e->getMessage(), __METHOD__);
            // If Cerbos fails, deny by default (fail closed)
            return false;
        }
    }

    /**
     * Fallback to mdmsoft RBAC check
     */
    protected function checkMdmPermission($route)
    {
        try {
            $user = Yii::$app->user;

            // Check if user has permission via RBAC
            if ($user->can($route)) {
                return true;
            }

            // Check with wildcards (e.g., 'controller/*')
            $parts = explode('/', $route);
            while (array_pop($parts) !== null) {
                $wildcardRoute = implode('/', $parts) . '/*';
                if ($user->can($wildcardRoute)) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            Yii::warning("mdmsoft RBAC check failed: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Get resource mapping for a route
     */
    protected function getResourceMapping($route)
    {
        // Direct match
        if (isset($this->resourceMap[$route])) {
            return $this->resourceMap[$route];
        }

        // Wildcard match
        foreach ($this->resourceMap as $pattern => $mapping) {
            if ($this->matchPattern($pattern, $route)) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * Create default mapping based on route
     */
    protected function createDefaultMapping($route)
    {
        $parts = explode('/', $route);
        $controller = $parts[0] ?? 'default';
        $action = $parts[1] ?? 'index';

        // Map common CRUD actions
        $actionMap = [
            'index' => 'index',
            'view' => 'read',
            'create' => 'create',
            'update' => 'update',
            'delete' => 'delete',
        ];

        return [
            'resource' => $controller,
            'action' => $actionMap[$action] ?? $action,
        ];
    }

    /**
     * Get the route of the action
     */
    protected function getActionRoute($action)
    {
        $controller = $action->controller;
        $route = $controller->id . '/' . $action->id;

        if ($controller->module !== null && !$controller->module instanceof \yii\web\Application) {
            $route = $controller->module->id . '/' . $route;
        }

        return $route;
    }

    /**
     * Match pattern with route
     */
    protected function matchPattern($pattern, $route)
    {
        if ($pattern === $route) {
            return true;
        }

        // Convert wildcard pattern to regex
        $pattern = str_replace('*', '.*', $pattern);
        $pattern = '/^' . str_replace('/', '\/', $pattern) . '$/';

        return preg_match($pattern, $route) === 1;
    }

    /**
     * Deny access
     */
    protected function denyAccess($user)
    {
        if ($user->isGuest) {
            $user->loginRequired();
        } else {
            throw new ForbiddenHttpException(Yii::t('yii', 'You are not allowed to perform this action.'));
        }
    }
}