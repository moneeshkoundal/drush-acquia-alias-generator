<?php

namespace Drush\Commands\drush_acquia_alias_generator\Commands;

use AcquiaCloudApi\Connector\Client;
use AcquiaCloudApi\Connector\Connector;
use AcquiaCloudApi\Endpoints\Applications;
use AcquiaCloudApi\Endpoints\Environments;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush commands to generate Acquia Cloud Site Factory aliases.
 */
class AcquiaAliasCommands extends DrushCommands
{

  /**
   * The Acquia Cloud API connector.
   *
   * @var \AcquiaCloudApi\Connector\Client
   */
  protected $cloudApiClient;

  /**
   * The Acquia application ID.
   *
   * @var string
   */
  protected $appId;

  /**
   * Initialize and generate Acquia Cloud aliases.
   *
   * @command acquia:aliases:init
   * @aliases raisa,acquia-aliases
   * @usage drush acquia:aliases:init
   *   Generate Acquia Cloud Site Factory aliases.
   * @usage drush raisa
   *   Short alias to generate ACSF aliases.
   */
  public function generateAliasesAcquia()
  {
    $this->say('Generating Acquia Cloud aliases...');

    // Set the application ID.
    $this->setAppId();

    // Load Cloud API configuration.
    $this->loadCloudApiConfig();

    // Get site aliases from Acquia Cloud API.
    $aliases = $this->getSiteAliases();

    if (empty($aliases)) {
      throw new \Exception('No aliases returned from Acquia Cloud API.');
    }

    // Write aliases to files.
    $this->writeSiteAliases($aliases);

    $this->say(sprintf('Successfully generated %d alias file(s).', count($aliases)));
  }

  /**
   * Set the Acquia application ID.
   */
  protected function setAppId()
  {
    // Try to get from .acquia.yml first.
    $acquia_yml_path = getcwd() . '/.acquia.yml';
    if (file_exists($acquia_yml_path)) {
      $acquia_config = Yaml::parseFile($acquia_yml_path);
      if (isset($acquia_config['cloud']['appId'])) {
        $this->appId = $acquia_config['cloud']['appId'];
        $this->say(sprintf('Found Application ID in .acquia.yml: %s', $this->appId));
        return;
      }
    }

    // Try environment variable.
    if (getenv('ACQUIA_APP_ID')) {
      $this->appId = getenv('ACQUIA_APP_ID');
      $this->say(sprintf('Found Application ID in environment: %s', $this->appId));
      return;
    }

    // Prompt user for Application ID.
    $this->appId = $this->io()->ask('Enter your Acquia Cloud Application ID (UUID)');

    if (empty($this->appId)) {
      throw new \Exception('Application ID is required.');
    }

    // Save to .acquia.yml for future use.
    $acquia_config = ['cloud' => ['appId' => $this->appId]];
    file_put_contents($acquia_yml_path, Yaml::dump($acquia_config, 2, 2));
    $this->say(sprintf('Saved Application ID to .acquia.yml: %s', $this->appId));
  }

  /**
   * Load Acquia Cloud API configuration and initialize client.
   */
  protected function loadCloudApiConfig()
  {
    $config_path = getenv('HOME') . '/.acquia/cloud_api.conf';

    if (!file_exists($config_path)) {
      $this->say('');
      $this->say('Acquia Cloud API credentials not found at: ' . $config_path);
      $this->say('');
      $this->say('Create an API token at: https://cloud.acquia.com/a/profile/tokens');
      $this->say('');

      $api_key = $this->io()->ask('Enter your Acquia Cloud API Key (UUID format)');
      $api_secret = $this->io()->askHidden('Enter your Acquia Cloud API Secret');

      if (empty($api_key) || empty($api_secret)) {
        throw new \Exception('API Key and Secret are required.');
      }

      // Create directory if it doesn't exist.
      $config_dir = dirname($config_path);
      if (!is_dir($config_dir)) {
        mkdir($config_dir, 0700, TRUE);
      }

      // Save credentials.
      $config_content = json_encode([
        'key' => $api_key,
        'secret' => $api_secret,
      ], JSON_PRETTY_PRINT);
      file_put_contents($config_path, $config_content);
      chmod($config_path, 0600);

      $this->say(sprintf('Saved Acquia Cloud API credentials to: %s', $config_path));
      $this->say('');
    } else {
      $this->say(sprintf('Loading Acquia Cloud API credentials from: %s', $config_path));
    }

    // Load credentials and initialize client.
    $credentials = json_decode(file_get_contents($config_path), TRUE);

    // Handle different credential file formats.
    $key = NULL;
    $secret = NULL;

    // Format 1: Direct key/secret at root.
    if (isset($credentials['key']) && isset($credentials['secret'])) {
      $key = $credentials['key'];
      $secret = $credentials['secret'];
    }
    // Format 2: Keys array (Acquia CLI format).
    elseif (isset($credentials['keys']) && is_array($credentials['keys'])) {
      $keys = $credentials['keys'];
      $first_key = reset($keys);
      if ($first_key && isset($first_key['secret'])) {
        $key = key($keys);
        $secret = $first_key['secret'];
      }
    }
    // Format 3: acli_key format.
    elseif (isset($credentials['acli_key'])) {
      $key = $credentials['acli_key'];
      $secret = $credentials['acli_secret'] ?? '';
    }

    if (empty($key) || empty($secret)) {
      throw new \Exception('Invalid credentials file format. Could not find API key and secret.');
    }

    // Debug output (only in verbose mode).
    if ($this->output()->isVerbose()) {
      $this->say(sprintf('Using API Key: %s', substr($key, 0, 8) . '...'));
      $this->say(sprintf('Secret length: %d characters', strlen($secret)));
    }

    $this->setCloudApiClient($key, $secret);
  }

  /**
   * Set the Cloud API client.
   *
   * @param string $api_key
   *   The API key.
   * @param string $api_secret
   *   The API secret.
   */
  protected function setCloudApiClient($api_key, $api_secret)
  {
    $config = [
      'key' => $api_key,
      'secret' => $api_secret,
    ];
    $connector = new Connector($config);
    $this->cloudApiClient = Client::factory($connector);
  }

  /**
   * Get site aliases from Acquia Cloud API.
   *
   * @return array
   *   Array of site aliases keyed by site name.
   */
  protected function getSiteAliases()
  {
    $this->say('Fetching application environments from Acquia Cloud API...');

    try {
      $applications_api = new Applications($this->cloudApiClient);
      $environments_api = new Environments($this->cloudApiClient);

      $application = $applications_api->get($this->appId);
      $environments = $environments_api->getAll($this->appId);
    } catch (\Exception $e) {
      $error_msg = $e->getMessage();
      $this->logger()->error('API Error: ' . $error_msg);

      if (strpos($error_msg, 'access_token') !== FALSE || strpos($error_msg, 'invalid_client') !== FALSE) {
        $this->say('');
        $this->say('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->say('  OAuth Authentication Failed');
        $this->say('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->say('');
        $this->say('The credentials in ' . getenv('HOME') . '/.acquia/cloud_api.conf are invalid or expired.');
        $this->say('');
        $this->say('To fix this:');
        $this->say('  1. Delete the invalid credentials file:');
        $this->say('     rm ' . getenv('HOME') . '/.acquia/cloud_api.conf');
        $this->say('');
        $this->say('  2. Re-run this command to enter new credentials:');
        $this->say('     ddev drush raisa');
        $this->say('');
        $this->say('  3. Get new API credentials from:');
        $this->say('     https://cloud.acquia.com/a/profile/tokens');
        $this->say('');
        $this->say('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->say('');
      }

      throw new \Exception(sprintf('Failed to fetch from Acquia Cloud API: %s', $error_msg));
    }

    $hosting_type = isset($application->hosting->type) ? $application->hosting->type : 'ace';
    $this->say(sprintf('Detected hosting type: %s', strtoupper($hosting_type)));

    $aliases = [];

    if ($hosting_type === 'acsf') {
      // For ACSF, get sites from the factory.
      $aliases = $this->getAcsfAliases($environments);
    } else {
      // For ACE/ACP, create aliases for each environment.
      $aliases = $this->getAliases($application, $environments);
    }

    return $aliases;
  }

  /**
   * Get aliases for ACE/ACP applications.
   *
   * @param object $application
   *   The application object.
   * @param \AcquiaCloudApi\Response\EnvironmentsResponse $environments
   *   Environments response object.
   *
   * @return array
   *   Array of aliases.
   */
  protected function getAliases($application, $environments)
  {
    $aliases = [];
    $site_id = $application->hosting->id;

    foreach ($environments as $environment) {
      $env_name = $environment->name;

      // Get SSH URL.
      $ssh_url = $environment->sshUrl ?? '';
      if (empty($ssh_url)) {
        $this->logger()->warning(sprintf('No SSH URL found for environment: %s', $env_name));
        continue;
      }

      // Extract just the hostname from SSH URL (format: site.env@server.com)
      $ssh_parts = explode('@', $ssh_url);
      $ssh_host = isset($ssh_parts[1]) ? $ssh_parts[1] : $ssh_url;

      // Get first domain or use default
      $domains = $environment->domains ?? [];
      $uri = !empty($domains) ? $domains[0] : "default";

      $aliases[$env_name] = [
        'host' => $ssh_host,
        'user' => $site_id . '.' . $env_name,
        'root' => "/var/www/html/{$site_id}.{$env_name}/docroot",
        'uri' => $uri,
      ];

      $this->say(sprintf('  Added alias: @%s.%s', $site_id, $env_name));
    }

    return [$site_id => $aliases];
  }

  /**
   * Get aliases for ACSF applications.
   *
   * @param \AcquiaCloudApi\Response\EnvironmentsResponse $environments
   *   Environments response object.
   *
   * @return array
   *   Array of aliases keyed by site ID.
   */
  protected function getAcsfAliases($environments)
  {
    $this->say('');
    $this->say('ACSF application detected. Fetching sites data...');
    $this->say('');

    $sites = [];

    foreach ($environments as $environment) {
      $env_name = $environment->name;

      // Get SSH URL.
      $ssh_url = $environment->sshUrl ?? '';
      if (empty($ssh_url)) {
        $this->logger()->warning(sprintf('No SSH URL found for environment: %s', $env_name));
        continue;
      }

      // Extract hostname and user from SSH URL (format: site.env@server.com)
      $ssh_parts = explode('@', $ssh_url);
      $ssh_host = isset($ssh_parts[1]) ? $ssh_parts[1] : $ssh_url;
      $ssh_user = isset($ssh_parts[0]) ? $ssh_parts[0] : '';

      $this->say(sprintf('Gathering domains for environment: %s', $env_name));
      $domains = $environment->domains ?? [];
      $this->say(sprintf('  Found %d domains', count($domains)));

      // Try to fetch sites.json for ACSF site-specific aliases
      try {
        $acsf_sites = $this->getSitesJson($ssh_url, $ssh_user);

        if ($acsf_sites && isset($acsf_sites['sites'])) {
          foreach ($acsf_sites['sites'] as $name => $info) {
            $site_id = NULL;

            // Get site prefix from main domain
            if (strpos($name, '.acsitefactory.com') !== FALSE) {
              $acsf_site_name = explode('.', $name, 2);
              $site_id = $acsf_site_name[0];
            }

            // Only process sites with preferred domain
            if (!empty($site_id) && !empty($info['flags']['preferred_domain'])) {
              $docroot = '/var/www/html/' . $ssh_user . '/docroot';

              $sites[$site_id][$env_name] = [
                'uri' => $name,
                'host' => $ssh_host,
                'root' => $docroot,
                'user' => $ssh_user,
                'ssh' => [
                  'options' => '-p 22',
                ],
                'paths' => [
                  'dump-dir' => '/mnt/tmp',
                ],
              ];

              $this->say(sprintf('  Added alias: @%s.%s', $site_id, $env_name));
            }
          }
        }
      } catch (\Exception $e) {
        $this->logger()->warning(sprintf('Could not fetch ACSF data for %s: %s', $env_name, $e->getMessage()));

        // Fallback: create aliases from domains
        foreach ($domains as $domain) {
          // Skip wildcard domains
          if (strpos($domain, ':*') !== FALSE) {
            continue;
          }

          // Extract site ID from domain
          $site_id = NULL;
          if (strpos($domain, '.acsitefactory.com') !== FALSE) {
            $parts = explode('.', $domain, 2);
            $site_id = $parts[0];
          }

          if (!empty($site_id)) {
            $docroot = '/var/www/html/' . $ssh_user . '/docroot';

            $sites[$site_id][$env_name] = [
              'uri' => $domain,
              'host' => $ssh_host,
              'root' => $docroot,
              'user' => $ssh_user,
              'ssh' => [
                'options' => '-p 22',
              ],
              'paths' => [
                'dump-dir' => '/mnt/tmp',
              ],
            ];

            $this->say(sprintf('  Added alias: @%s.%s', $site_id, $env_name));
          }
        }
      }
    }

    return $sites;
  }

  /**
   * Get sites.json from ACSF environment via SCP.
   *
   * @param string $ssh_url
   *   The full SSH connection string (user.env@host.com).
   * @param string $remote_user
   *   The remote user (site.env format).
   *
   * @return array|null
   *   Parsed sites.json data or NULL on failure.
   */
  protected function getSitesJson($ssh_url, $remote_user)
  {
    $temp_dir = getenv('HOME') . '/.acquia';
    if (!is_dir($temp_dir)) {
      mkdir($temp_dir, 0700, TRUE);
    }

    $temp_file = $temp_dir . '/sites.json';
    $remote_path = "/mnt/files/{$remote_user}/files-private/sites.json";

    // Use SCP to copy the file
    $command = sprintf(
      'scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -P 22 %s:%s %s 2>&1',
      escapeshellarg($ssh_url),
      escapeshellarg($remote_path),
      escapeshellarg($temp_file)
    );

    exec($command, $output, $return_code);

    if ($return_code !== 0 || !file_exists($temp_file)) {
      throw new \Exception('Unable to fetch sites.json: ' . implode("\n", $output));
    }

    $content = file_get_contents($temp_file);
    $sites_json = json_decode($content, TRUE);

    // Clean up temp file
    @unlink($temp_file);

    return $sites_json;
  }

  /**
   * Write site aliases to YAML files.
   *
   * @param array $aliases
   *   Array of aliases keyed by site ID.
   */
  protected function writeSiteAliases(array $aliases)
  {
    $drush_dir = getcwd() . '/drush/sites';

    if (!is_dir($drush_dir)) {
      mkdir($drush_dir, 0755, TRUE);
      $this->say(sprintf('Created directory: %s', $drush_dir));
    }

    foreach ($aliases as $site_id => $site_aliases) {
      $alias_file = sprintf('%s/%s.site.yml', $drush_dir, $site_id);

      $yaml_content = Yaml::dump($site_aliases, 3, 2);
      file_put_contents($alias_file, $yaml_content);

      $this->say(sprintf('Wrote alias file: %s', basename($alias_file)));
    }
  }
}
