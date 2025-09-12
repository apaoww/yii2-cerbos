# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2024-12-09

### Added
- Initial release of yii2-cerbos extension
- **CerbosAccessControl** component for automatic route-based access control
- **CerbosHttpAuth** component for HTTP API integration with Cerbos server
- System prefix support for resource names (e.g., `imap_` prefix)
- RBAC fallback functionality for gradual migration from existing systems
- Global application-wide access control configuration via `'as access'`
- Per-controller access control configuration via behaviors
- Flexible resource mapping with support for:
  - Static resource/action mappings
  - Dynamic resource/action mappings using callable functions
  - Custom resource ID extraction
  - Custom resource attributes extraction
- Built-in action mapping for common CRUD operations:
  - `index` → `index`
  - `view` → `read`
  - `create` → `create`
  - `update` → `update`
  - `delete` → `delete`
- Comprehensive error handling and logging
- Batch permission checking support
- Debug integration with detailed logging
- Extensive documentation with real-world examples
- Support for excluding routes from access control (e.g., debug, gii)
- Compatible with Yii2 framework 2.0.14+ and PHP 8.0+

### Features
- **Easy Integration**: Simple configuration in application config files
- **Flexible Architecture**: Works with both global and per-controller setups
- **Production Ready**: Built-in error handling and fallback mechanisms
- **Developer Friendly**: Comprehensive logging and debug information
- **Migration Support**: RBAC fallback for gradual transition to Cerbos
- **Extensible**: Support for custom resource mapping and extraction logic

### Dependencies
- PHP >= 8.0
- yiisoft/yii2 ~2.0.14
- guzzlehttp/guzzle ^7.0
- Active Cerbos server instance

[Unreleased]: https://github.com/apaoww/yii2-cerbos/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/apaoww/yii2-cerbos/releases/tag/v1.0.0