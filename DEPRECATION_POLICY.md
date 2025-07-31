# Glueful Framework Deprecation Policy

## Overview

This document defines the official deprecation policy for the Glueful Framework, ensuring predictable and developer-friendly management of API changes while maintaining backward compatibility.

## Deprecation Principles

### 1. Semantic Versioning Commitment
- **MAJOR** versions may contain breaking changes
- **MINOR** versions introduce new features with backward compatibility
- **PATCH** versions contain only backward-compatible bug fixes
- Deprecations are introduced in **MINOR** versions and removed in **MAJOR** versions

### 2. Minimum Deprecation Period
- **Standard Deprecation**: At least **2 major versions** before removal
- **Critical Security Issues**: May be removed in next MAJOR version with extended notice
- **Experimental Features**: May be removed in next MINOR version if marked as experimental

## Deprecation Process

### 1. Announcement Phase
When a feature is deprecated:
- Add `@deprecated` annotation with version and alternative
- Include in CHANGELOG.md with migration guidance
- Add deprecation warning to framework documentation
- Notify via official channels (GitHub, documentation, community)

**Example:**
```php
/**
 * Get database connection
 * @deprecated since v1.2.0, use getPDO() instead. Will be removed in v3.0.0
 * @see getPDO()
 */
public function getConnection(): PDO
{
    trigger_error('getConnection() is deprecated, use getPDO() instead', E_USER_DEPRECATED);
    return $this->getPDO();
}
```

### 2. Warning Phase
- Runtime deprecation warnings in development mode
- Documentation clearly marks deprecated features
- Migration guides provided for all deprecations
- IDE support through proper annotations

### 3. Removal Phase
- Deprecated features removed in scheduled MAJOR version
- Clear migration path documented
- Breaking changes listed in upgrade guide

## Types of Deprecations

### 1. Method/Function Deprecation
```php
// Deprecated method
/**
 * @deprecated since v1.1.0, use newMethod() instead. Will be removed in v3.0.0
 */
public function oldMethod() { }

// Replacement method  
public function newMethod() { }
```

### 2. Parameter Deprecation
```php
/**
 * @param string $newParam New parameter name
 * @param string $oldParam Deprecated since v1.1.0, use $newParam. Will be removed in v3.0.0
 */
public function method($newParam, $oldParam = null) { }
```

### 3. Configuration Deprecation
- Old configuration keys supported with warnings
- New configuration structure documented
- Automatic migration where possible

### 4. Class/Interface Deprecation
```php
/**
 * @deprecated since v1.2.0, use NewClassName instead. Will be removed in v3.0.0
 */
class OldClassName { }
```

## Developer Communication

### 1. Documentation Updates
- Deprecation notices in API documentation
- Migration guides for complex changes
- Examples showing old vs new approaches
- Timeline for removal clearly stated

### 2. Release Notes
- All deprecations listed in CHANGELOG.md
- Migration instructions included
- Impact assessment provided
- Alternative solutions documented

### 3. Runtime Notifications
- Development mode shows deprecation warnings
- Production mode logs deprecation usage
- Optional strict mode fails on deprecated usage
- Clear error messages with solutions

## Migration Support

### 1. Backward Compatibility
- Deprecated features continue to work during deprecation period
- No breaking changes in MINOR versions
- Wrapper methods/classes provided where possible
- Automated migration tools for complex changes

### 2. Migration Guides
Every deprecation includes:
- **What**: What is being deprecated
- **Why**: Reason for deprecation  
- **When**: Timeline for removal
- **How**: Step-by-step migration instructions
- **Examples**: Before and after code samples

### 3. Tools and Utilities
- CLI command to detect deprecated usage: `./glueful deprecation:scan`
- IDE plugins for deprecation detection
- Automated refactoring suggestions where possible
- Test suite helpers for deprecation-free testing

## Exception Handling

### 1. Security-Related Deprecations
- May have shorter deprecation periods
- Immediate warnings in all environments
- Expedited removal timeline with clear justification
- Alternative security measures provided

### 2. Critical Bug Fixes
- Deprecated behavior causing data loss or corruption
- May be removed in next PATCH version
- Extensive communication and migration support
- Emergency upgrade procedures documented

### 3. External Dependency Changes
- Framework adapts to upstream deprecations
- Users notified of indirect impacts
- Alternative implementations provided
- Upgrade paths for dependency changes

## Version-Specific Policies

### v1.x Series (Current Stable)
- Standard 2-major-version deprecation policy
- Removal only in v3.0.0 or later
- Maximum backward compatibility maintained
- Clear upgrade path to v2.x when available

### v2.x Series (Future)
- Enhanced deprecation tooling
- Automated migration utilities
- Improved developer warnings
- Streamlined upgrade process

## Community Involvement

### 1. Feedback Process
- 30-day comment period for major deprecations
- Community input considered for deprecation timeline
- Alternative proposals evaluated
- Impact assessment includes community usage data

### 2. Early Warning System
- Pre-release versions include upcoming deprecations
- Beta testing program for breaking changes
- Community preview of migration guides
- Feedback incorporation before final release

## Compliance and Monitoring

### 1. Internal Reviews
- All deprecations reviewed by core team
- Impact assessment required
- Migration guide quality assurance
- Timeline feasibility evaluation

### 2. Community Support
- Dedicated support for deprecation-related issues
- Migration assistance for complex cases
- Community forum for deprecation discussions
- Regular office hours for deprecation help

## Examples and Templates

### Migration Guide Template
```markdown
## Migration: oldMethod() → newMethod()

**Deprecated in:** v1.2.0  
**Removed in:** v3.0.0  
**Reason:** Performance improvement and API consistency

### Before (Deprecated)
```php
$result = $service->oldMethod($param1, $param2);
```

### After (Recommended)
```php
$result = $service->newMethod([
    'param1' => $param1,
    'param2' => $param2
]);
```

### Automated Migration
```bash
./glueful migrate:deprecation --from=oldMethod --to=newMethod
```
```

---

## Policy Updates

This deprecation policy is reviewed annually and updated as needed. Major changes to this policy follow the same deprecation process outlined above.

**Last Updated:** January 31, 2025  
**Next Review:** January 31, 2026  
**Policy Version:** 1.0.0