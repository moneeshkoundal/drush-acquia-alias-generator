<?php

namespace AcquiaPso\DrushAcquiaAliasGenerator\Commands;

use Drush\Commands\DrushCommands;
use AcquiaCloudApi\Connector\Client;
use AcquiaCloudApi\Connector\Connector;
use AcquiaCloudApi\Endpoints\Account;
use AcquiaCloudApi\Endpoints\Applications;
use AcquiaCloudApi\Endpoints\Environments;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush commands for generating Acquia Cloud site aliases.
 */
class AcquiaAliasCommands extends DrushCommands {

  /**
   * Cloud API client.
   *
   * @var \AcquiaCloudApi\Connector\Client
   */
  protected $cloudApiClient;

  /**
   * App id.
   *
   * @var string
   */
  protected $appId;

  /**
   * Cloud config dir.
   *
   * @var string
   */
  protected $cloudConfDir;

  /**
   * Cloud config filename.
   *
   * @var string
   */
  protected $cloudConfFileName;

  /**
   * Cloud config file path.
   *
   * @var string
   */
  protected $cloudConfFilePath;

  /**
   * Site alias dir.
   *
   * @var string
   */
  protected $siteAliasDir;

  /**
   * Generates new Acquia site aliases for Drush.
   *
   * @command acquia:aliases:init
   * @aliases raisa,acquia-aliases
   * @usage drush acquia:aliases:init
   *   Generate Acquia Cloud site aliases.
   * @usage ddev drush raisa
   *   Generate Acquia Cloud site aliases in DDEV.
   */
  public function generateAliasesAcquia() {
    $this->cloudConfDir = $_SERVER['HOME'] . '/.acquia';
    $this->cloudConfFileName = 'cloud_api.conf';
    $this->cloudConfFilePath = $this->cloudConfDir . '/' . $this->cloudConfFileName;

    // Determine the site alias directory
    $this->siteAliasDir = $this->getSiteAliasDir();

    // Set the application ID
    $this->setAppId();

    // Load Cloud API configuration
    $cloudApiConfig = $this->loadCloudApiConfig();
    $this->setCloudApiClient($cloudApiConfig['key'], $cloudApiConfig['secret']);

    $this->logger()->info("Gathering site info from Acquia Cloud...");

    $applicationAdapter = new Applications($this->cloudApiClient);
    $site = $applicationAdapter->get($this->appId);

    $error = FALSE;
    try {
      $this->getSiteAliases($site);
    }
    catch (\Exception $e) {
      $error = TRUE;
      $this->logger()->error("Did not write aliases for {$site->name}. Error: " . $e->getMessage());
    }

    if (!$error) {
      $this->logger()->success("Aliases were written successfully!");
      $this->logger()->info("Run 'drush sa' or 'ddev drush sa' to see them.");
    }
  }

  /**
   * Gets the site alias directory path.
   *
   * @return string
   *   The site alias directory path.
   */
  protected function getSiteAliasDir() {
    // Try to find drush/sites directory relative to current directory
    $possiblePaths = [
      getcwd() . '/drush/sites',
      getcwd() . '/../drush/sites',
      $_SERVER['HOME'] . '/.drush/sites',
    ];

    foreach ($possiblePaths as $path) {
      if (is_dir(dirname($path))) {
        return $path;
      }
    }

    // Default to drush/sites in current directory
    return getcwd() . '/drush/sites';
  }

  /**
   * Sets the Acquia application ID from environment or prompt.
   */
  protected function setAppId() {
    // Try to get from environment variable first
    if ($app_id = getenv('ACQUIA_APP_ID')) {
      $this->appId = $app_id;
      $this->logger()->info("Using application ID from ACQUIA_APP_ID environment variable.");
      return;
    }

    // Try to load from config file
    $configFile = getcwd() . '/.acquia.yml';
    if (file_exists($configFile)) {
      $config = Yaml::parseFile($configFile);
      if (isset($config['cloud']['appId'])) {
        $this->appId = $config['cloud']['appId'];
        $this->logger()->info("Using application ID from .acquia.yml");
        return;
      }
    }

    // Prompt the user
    $this->logger()->info("To generate aliases for Acquia Cloud, we need your application ID.");
    $this->logger()->info("See: https://docs.acquia.com/acquia-cloud/manage/applications/#obtaining-your-subscription-s-application-id");
    $this->appId = $this->io()->ask('Please enter your Acquia Cloud application ID');

    // Save to config file
    $this->writeAppConfig($this->appId);
  }

  /**
   * Writes appId to .acquia.yml config file.
   *
   * @param string $app_id
   *   The Acquia Cloud application UUID.
   */
  protected function writeAppConfig($app_id) {
    $configFile = getcwd() . '/.acquia.yml';

    $config = [];
    if (file_exists($configFile)) {
      $config = Yaml::parseFile($configFile);
    }

    $config['cloud']['appId'] = $app_id;

    try {
      file_put_contents($configFile, Yaml::dump($config, 4, 2));
      $this->logger()->success("Application ID saved to .acquia.yml");
    }
    catch (\Exception $e) {
      $this->logger()->warning("Could not save application ID to .acquia.yml: " . $e->getMessage());
    }
  }

  /**
   * Loads CloudAPI token from file or prompts for credentials.
   *
   * @return array
   *   An array of CloudAPI token configuration.
   */
  protected function loadCloudApiConfig() {
    if (!$config = $this->loadCloudApiConfigFile()) {
      $config = $this->askForCloudApiCredentials();
    }
    return $config;
  }

  /**
   * Load existing credentials from disk.
   *
   * @return bool|array
   *   Returns credentials as array on success, or FALSE on failure.
   */
  protected function loadCloudApiConfigFile() {
    if (file_exists($this->cloudConfFilePath)) {
      return (array) json_decode(file_get_contents($this->cloudConfFilePath));
    }
    return FALSE;
  }

  /**
   * Interactive prompt to get Cloud API credentials.
   *
   * @return array
   *   Returns credentials as array.
   *
   * @throws \Exception
   */
  protected function askForCloudApiCredentials() {
    $this->logger()->info("You may generate new API tokens at: https://cloud.acquia.com/app/profile/tokens");

    $key = $this->io()->ask('Please enter your Acquia Cloud API key');
    $secret = $this->io()->askHidden('Please enter your Acquia Cloud API secret');

    if (empty($key) || empty($secret)) {
      throw new \Exception("API key and secret are required.");
    }

    // Attempt to set client to check credentials
    $this->setCloudApiClient($key, $secret);

    $config = [
      'key' => $key,
      'secret' => $secret,
    ];

    $this->writeCloudApiConfig($config);

    return $config;
  }

  /**
   * Writes configuration to local file.
   *
   * @param array $config
   *   An array of CloudAPI configuration.
   */
  protected function writeCloudApiConfig(array $config) {
    if (!is_dir($this->cloudConfDir)) {
      mkdir($this->cloudConfDir, 0700, TRUE);
    }

    file_put_contents($this->cloudConfFilePath, json_encode($config));
    chmod($this->cloudConfFilePath, 0600);

    $this->logger()->success("Credentials were written to {$this->cloudConfFilePath}");
  }

  /**
   * Tests CloudAPI client authentication credentials.
   *
   * @param string $key
   *   The Acquia token public key.
   * @param string $secret
   *   The Acquia token secret key.
   *
   * @throws \Exception
   */
  protected function setCloudApiClient($key, $secret) {
    try {
      $connector = new Connector([
        'key' => $key,
        'secret' => $secret,
      ]);
      $cloud_api = Client::factory($connector);

      // Test authentication by calling account endpoint
      $account = new Account($cloud_api);
      $account->get();

      $this->cloudApiClient = $cloud_api;
      $this->logger()->success("Successfully authenticated with Acquia Cloud API.");
    }
    catch (\Exception $e) {
      if (strpos($e->getMessage(), '403') !== FALSE || strpos($e->getMessage(), 'Forbidden') !== FALSE) {
        throw new \Exception("Invalid credentials. Failed to authenticate with Acquia Cloud API.");
      }
      throw new \Exception("Error connecting to Acquia Cloud API: " . $e->getMessage());
    }
  }

  /**
   * Gets generated drush site aliases.
   *
   * @param object $site
   *   The Acquia subscription that aliases will be generated for.
   *
   * @throws \Exception
   */
  protected function getSiteAliases($site) {
    $sites = [];

    $this->logger()->info("Gathering environments from Acquia Cloud...");
    $environmentAdapter = new Environments($this->cloudApiClient);
    $environments = $environmentAdapter->getAll($site->uuid);

    $hosting = $site->hosting->type;
    $site_split = explode(':', $site->hosting->id);

    foreach ($environments as $env) {
      $domains = $env->domains;
      $this->logger()->info("Found " . count($domains) . " domains for environment {$env->name}");

      $ssh_split = explode('@', $env->sshUrl);
      $envName = $env->name;
      $remoteHost = $ssh_split[1];
      $remoteUser = $ssh_split[0];

      if (in_array($hosting, ['ace', 'acp'])) {
        $siteID = $site_split[1];
        $uri = $env->domains[0];
        $siteAlias = $this->getAliases($uri, $envName, $remoteHost, $remoteUser, $siteID);
        if ($siteAlias) {
          $sites[$siteID][$envName] = $siteAlias[$envName];
        }
      }

      if ($hosting == 'acsf') {
        $this->logger()->info("ACSF project detected - generating sites data...");
        try {
          $acsf_sites = $this->getSitesJson($env->sshUrl, $remoteUser);

          if ($acsf_sites && isset($acsf_sites['sites'])) {
            foreach ($acsf_sites['sites'] as $name => $info) {
              $uri = NULL;
              $siteID = NULL;

              // Get site prefix from main domain
              if (strpos($name, '.acsitefactory.com') !== FALSE) {
                $acsf_site_name = explode('.', $name, 2);
                $siteID = $acsf_site_name[0];
              }

              // Only process sites with preferred domain
              if (!empty($siteID) && !empty($info['flags']['preferred_domain'])) {
                $uri = $name;
                $siteAlias = $this->getAliases($uri, $envName, $remoteHost, $remoteUser, $siteID);
                if ($siteAlias) {
                  $sites[$siteID][$envName] = $siteAlias[$envName];
                }
              }
            }
          }
        }
        catch (\Exception $e) {
          $this->logger()->warning("Could not fetch ACSF data for {$envName}: " . $e->getMessage());
        }
      }
    }

    // Write the alias files to disk
    $count = 0;
    foreach ($sites as $siteID => $aliases) {
      $this->writeSiteAliases($siteID, $aliases);
      $count++;
    }

    $this->logger()->success("Generated {$count} site alias file(s).");
  }

  /**
   * Generates a site alias for valid domains.
   *
   * @param string $uri
   *   The unique site url.
   * @param string $envName
   *   The current environment.
   * @param string $remoteHost
   *   The remote host.
   * @param string $remoteUser
   *   The remote user.
   * @param string $siteID
   *   The siteID / group.
   *
   * @return array|null
   *   The full alias for this site, or NULL if skipped.
   */
  protected function getAliases($uri, $envName, $remoteHost, $remoteUser, $siteID) {
    // Skip wildcard domains
    if (strpos($uri, ':*') !== FALSE) {
      return NULL;
    }

    $docroot = '/var/www/html/' . $remoteUser . '/docroot';

    $alias = [];
    $alias[$envName] = [
      'uri' => $uri,
      'host' => $remoteHost,
      'root' => $docroot,
      'user' => $remoteUser,
      'ssh' => [
        'options' => '-p 22',
      ],
      'paths' => [
        'dump-dir' => '/mnt/tmp',
      ],
    ];

    return $alias;
  }

  /**
   * Gets ACSF sites info without secondary API calls or Drupal bootstrap.
   *
   * @param string $sshFull
   *   The full ssh connection string for this environment.
   * @param string $remoteUser
   *   The site.env remoteUser string used in the remote private files path.
   *
   * @return array|null
   *   An array of ACSF site data for the current environment.
   */
  protected function getSitesJson($sshFull, $remoteUser) {
    $this->logger()->info("Fetching ACSF sites.json...");

    $tempFile = $this->cloudConfDir . '/sites.json';
    $remotePath = "/mnt/files/{$remoteUser}/files-private/sites.json";

    // Use scp to copy the file
    $command = sprintf(
      'scp -o StrictHostKeyChecking=no -P 22 %s:%s %s 2>&1',
      escapeshellarg($sshFull),
      escapeshellarg($remotePath),
      escapeshellarg($tempFile)
    );

    exec($command, $output, $returnCode);

    if ($returnCode !== 0 || !file_exists($tempFile)) {
      $this->logger()->warning("Unable to fetch ACSF sites.json: " . implode("\n", $output));
      return NULL;
    }

    $response_body = file_get_contents($tempFile);
    $sites_json = json_decode($response_body, TRUE);

    // Clean up temp file
    @unlink($tempFile);

    return $sites_json;
  }

  /**
   * Writes site aliases to disk.
   *
   * @param string $site_id
   *   The siteID or alias group.
   * @param array $aliases
   *   The alias array for this site group.
   *
   * @throws \Exception
   */
  protected function writeSiteAliases($site_id, array $aliases) {
    if (!is_dir($this->siteAliasDir)) {
      mkdir($this->siteAliasDir, 0755, TRUE);
    }

    $filePath = $this->siteAliasDir . '/' . $site_id . '.site.yml';

    if (file_exists($filePath)) {
      if (!$this->io()->confirm("File {$filePath} already exists. Overwrite?", TRUE)) {
        throw new \Exception("Aborted: User chose not to overwrite existing file.");
      }
    }

    file_put_contents($filePath, Yaml::dump($aliases, 4, 2));
    $this->logger()->success("Wrote aliases to {$filePath}");
  }

}
