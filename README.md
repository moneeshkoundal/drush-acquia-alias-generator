# Drush Acquia Alias Generator

A Drush command to automatically generate site aliases for Acquia Cloud (ACE, ACP, and ACSF) projects.

## Features

- ✅ Supports ACE (Acquia Cloud Enterprise)
- ✅ Supports ACP (Acquia Cloud Professional)
- ✅ Supports ACSF (Acquia Cloud Site Factory)
- ✅ Automatic credential caching
- ✅ Environment variable support
- ✅ DDEV compatible
- ✅ No BLT dependency

## Installation

### Via Composer (Recommended)

```bash
composer require acquia-pso/drush-acquia-alias-generator --dev
```

### For DDEV Projects

```bash
ddev composer require acquia-pso/drush-acquia-alias-generator --dev
```

### From GitHub (before Packagist submission)

Add to your `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/acquia-pso/drush-acquia-alias-generator"
    }
  ],
  "require-dev": {
    "acquia-pso/drush-acquia-alias-generator": "^1.0"
  }
}
```

## Prerequisites

You need:
1. Acquia Cloud API credentials (key and secret)
2. Your Acquia Cloud Application ID (UUID)

### Getting API Credentials

1. Go to https://cloud.acquia.com/app/profile/tokens
2. Create a new API token
3. Save the **API Key** and **API Secret**

### Finding Your Application ID

1. Go to https://cloud.acquia.com
2. Select your application
3. The UUID is in the URL or application details

## Usage

### Basic Usage

```bash
drush acquia:aliases:init
# or
drush raisa
```

### DDEV Usage

```bash
ddev drush raisa
```

### First Run

You'll be prompted for:
1. Application ID (saved to `.acquia.yml`)
2. API Key and Secret (saved to `~/.acquia/cloud_api.conf`)

### Configuration

**Environment Variable:**
```bash
export ACQUIA_APP_ID="your-app-uuid"
```

**Config File (`.acquia.yml`):**
```yaml
cloud:
  appId: 'your-app-uuid'
```

**Important:** Add `.acquia.yml` to `.gitignore`!

## Output

Aliases are generated in `drush/sites/` as `{site-id}.site.yml` files.

View them:
```bash
drush sa
```

## Using Aliases

```bash
# Database sync
drush sql:sync @site.prod @self

# File sync
drush rsync @site.prod:%files @self:%files

# Remote commands
drush @site.prod cr
```

## License

GPL-2.0-or-later

## Support

Issues: https://github.com/acquia-pso/drush-acquia-alias-generator/issues
