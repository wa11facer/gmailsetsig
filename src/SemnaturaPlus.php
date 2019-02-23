<?php

namespace Moometric;

/**
 * GSuite GMail Signature Manager
 *
 * @author MooMaster / Gabriel Buta
 */
class SemnaturaPlus {

  # main info
  const ADMIN_EMAIL = 'user@domain.com';
  const DOMAIN = 'domain.com';
  # account types
  const PERSON = 'persoana';
  const BRANCH = 'filiala';
  const GENERIC = 'generic';
  # config files
  const ADDRESSES = 'addresses.json';
  const FILTER = 'filter-users.json';
  const OVERRIDES = 'overrides.json';
  const USER_DATA = 'user-data.json';

  # logging
  protected $logger;
  # Object variables updated by functions
  public $user_email;
  public $user_alias;
  public $current_email_signature;
  public $previous_user_data = [];
  public $filter_users = [];
  public $overrides = [];
  public $addresses = [];
  # Google services that need to be instantiated
  public $google_client;
  public $google_service_directory;
  public $google_service_gmail;
  public $google_service_gmail_send_as;
  # Setting variables and arrays
  public $setting_remove_blanks = TRUE;
  public $setting_email_template;
  # Filter settings
  public $setting_skip_conditions = [];
  public $setting_email_filter = [];
  public $setting_testing_mode = TRUE;
  public $setting_preview_template = TRUE;
  # Path settings for local variables/templates
  public $setting_json_path = __DIR__ . '/../local_vars/';
  public $setting_signature_path = __DIR__ . '/../signatures/';
  public $setting_service_account_path = __DIR__ . '/../local_vars/';

  public function __construct(object $logger) {
    $this->logger = $logger;

    $this->logger->info('Initial settings: TEST->' . var_export($this->setting_testing_mode, TRUE) . ', PREVIEW->' . var_export($this->setting_preview_template, TRUE) . ', REMOVE_BLANKS->' . var_export($this->setting_remove_blanks, TRUE));

    $this->logger->debug("Initializing Signature class");
    if ( file_exists($this->setting_service_account_path . 'service-account.json') ) {
      $this->setting_service_account_path = 'GOOGLE_APPLICATION_CREDENTIALS=' . $this->setting_service_account_path . 'service-account.json';
      putenv($this->setting_service_account_path);
      $logger->debug("Found the application credentials, initializing the Google client");
      $this->googleClientConnect();
    }

    $this->previous_user_data = Helper::readConfigFile($this->setting_json_path . self::USER_DATA, $this->logger);
    $this->filter_users = Helper::readConfigFile($this->setting_json_path . self::FILTER, $this->logger);
    $this->addresses = Helper::readConfigFile($this->setting_json_path . self::ADDRESSES, $this->logger);
    $this->overrides = Helper::readConfigFile($this->setting_json_path . self::OVERRIDES, $this->logger);

    # clean-up any left-over fields in alreadyUpdated tags
    foreach ( $this->overrides as $alias => $fields ) {
      if ( isset($fields['alreadyUpdated']) ) {
        foreach ( $fields['alreadyUpdated'] as $key => $field ) {
          if ( !isset($fields[$field]) ) {
            unset($this->overrides[$alias]['alreadyUpdated'][$key]);
            $this->logger->info("Cleaned-up left-over key (" . $field . ") in the field (alreadyUpdated) of " . $alias . " from " . self::OVERRIDES);
          }
        }
      }
    }
  }

  # Core Google API Client functions
  public function googleClientConnect() {
    if ( $this->setting_service_account_path ) {
      putenv($this->setting_service_account_path);
      $this->google_client = new \Google_Client();
      $this->google_client->useApplicationDefaultCredentials();
      $this->google_client->setScopes(array('https://www.googleapis.com/auth/admin.directory.user', 'https://www.googleapis.com/auth/admin.directory.user.alias', 'https://www.googleapis.com/auth/admin.directory.userschema', 'https://www.googleapis.com/auth/gmail.settings.basic', 'https://www.googleapis.com/auth/gmail.settings.sharing'));
      $this->google_service_directory = new \Google_Service_Directory($this->google_client);
      $this->google_service_gmail = new \Google_Service_Gmail($this->google_client);
      $this->google_service_gmail_send_as = new \Google_Service_Gmail_SendAs();
    } else {
      $this->logger->error("Must set the full path to service-account.json file - Cannot find file $this->setting_service_account_path");
      Helper::exit();
    }

    return $this;
  }

  public function getUsersList() {
    $this->logger->info('Getting the users for ' . self::ADMIN_EMAIL . ' / ' . self::DOMAIN);

    $this->google_client->setSubject(self::ADMIN_EMAIL);
    $result = $this->google_service_directory->users->listUsers(['domain' => (string)self::DOMAIN, "projection" => "full", "maxResults" => 500]);
    $this->logger->debug('List of users: ', (array)$result);

    return $result;
  }

  public function getUser(): object {
    $this->logger->info("Getting the User $this->user_email");

    $this->google_client->setSubject(self::ADMIN_EMAIL);
    $optParams = array("projection" => "full");

    $result = $this->google_service_directory->users->get("$this->user_email", $optParams);
    $this->logger->debug("Obtained the following info on user $this->user_email: ", $result);

    return $result;
  }

  public function getUserAlias(): array {
    $this->logger->info("Getting all the SendAs Aliases for the user $this->user_email");
    $alias_array = [];
    $this->google_client->setSubject($this->user_email);
    $response = $this->google_service_gmail->users_settings_sendAs->listUsersSettingsSendAs($this->user_email);
    foreach ( $response as $key ) {
      $alias_array[] = $key['sendAsEmail'];
    }

    $this->logger->debug("The following SendAs Aliases were found for the user $this->user_email: ", $alias_array);
    return $alias_array;
  }

  public function getUserSignature() {
    $this->logger->info("Getting the current signature of user $this->user_email");

    $this->google_client->setSubject($this->user_email);
    $result = $this->google_service_gmail->users_settings_sendAs->get($this->user_email, $this->user_alias)->getSignature();
    $this->logger->debug("The current signature of user $this->user_email is: $result");

    return $result;
  }

  public function setUserSignature() {
    $this->logger->info("Setting the signature for the alias $this->user_alias belonging to user $this->user_email.");

    $response = [];
    if ( $this->setting_testing_mode == TRUE ) {
      $this->logger->info("Currently in testing mode - no signature will be updated for alias $this->user_alias");
    } else {
      $this->google_client->setSubject($this->user_email);
      $this->google_service_gmail_send_as->setSignature($this->current_email_signature);
      $response = $this->google_service_gmail->users_settings_sendAs->patch($this->user_email, $this->user_alias, $this->google_service_gmail_send_as);
      $this->logger->debug('Set the signature, the following response was received:', (array)$response);
    }
    if ( $this->setting_preview_template == TRUE ) {
      echo $this->current_email_signature;
    }

    return $response;
  }

  public function updateSignatures(array $filtered_users) {
    foreach ( $filtered_users as $key => $value ) {
      if ( $this->functionValidateUsers($key, $filtered_users) == FALSE ) {
        continue;
      }
      $this->generateSigFromTemplate($filtered_users[$key]);
      $this->user_email = $filtered_users[$key]['primaryEmail'];
      $this->user_alias = $filtered_users[$key]['alias'];
      $this->setUserSignature();
      $this->markOverridesAsDone($filtered_users[$key]);
    }

    $this->logger->info('Marking the manual overrides as Updated');
    $this->logger->debug('The updated manual overrides: ', $this->overrides);
    file_put_contents($this->setting_json_path . self::OVERRIDES, json_encode($this->overrides, JSON_PRETTY_PRINT));

    return $this;
  }

  public function getMergeFields(): array {
    $all_fields = [];
    foreach ( $this->previous_user_data as $alias => $fields ) {
      $all_fields = array_merge($all_fields, $fields);
    }

    $this->logger->info('All the data fields received from GSuite: ', array_keys($all_fields));
    return array_keys($all_fields);
  }

  private function functionStripUserAttributes($user_array): array {
    $this->logger->info('Filtering out the unneeded data for ' . $user_array['primaryEmail']);
    $count = 0;
    $phoneCount = 0;
    $mdSchemas = [];
    $users = [];
    $filtered_users = [];

    if ( $user_array['phones'] ) {
      foreach ( $user_array['phones'] as $key ) {
        if ( $key["value"] ) {
          $mdSchemas["phone" . $phoneCount] = $key["value"];
          ++$phoneCount;
        }
      }
    }
    if ( $user_array['organizations'] ) {
      foreach ( $user_array['organizations'] as $orgs ) {
        foreach ( $orgs as $orgKey => $orgValue ) {
          if ( $orgValue ) {
            $mdSchemas[$orgKey] = $orgValue;
          }
        }
      }
    }
    if ( $user_array['customSchemas'] ) {
      foreach ( $user_array['customSchemas'] as $schemas ) {
        foreach ( $schemas as $schemaKey => $schemaValue ) {
          if ( $schemaValue ) {
            $mdSchemas[$schemaKey] = $schemaValue;
          }
        }
      }
    }
    $this->user_email = $user_array['primaryEmail'];
    $aliases = $this->getUserAlias();
    foreach ( $aliases as $key => $value ) {
      $users[] = ["primaryEmail" => $user_array['primaryEmail'],
          "alias" => $value,
          "fullName" => $user_array['name']['fullName'],
          "givenName" => $user_array['name']['givenName'],
          "familyName" => $user_array['name']['familyName'],
          "websites" => $user_array['websites'],
          "thumbnailPhotoUrl" => $user_array["thumbnailPhotoUrl"]
      ];
      foreach ( $mdSchemas as $key => $value ) {
        if ( $value ) {
          $users[$count] += ["$key" => "$value"];
        }
      }
      $filtered_users[$users[$count]['alias']] = $users[$count];
      ++$count;
    }

    $this->logger->debug('All the GSuite aliases after filtering out unneeded data: ', $filtered_users);
    return $filtered_users;
  }

  public function functionValidateUsers(string $key, array $filtered_users) {
    $this->logger->info('Validating (or NOT) the user ' . $filtered_users[$key]['alias']);
    if ( !empty($this->setting_skip_conditions) ) {
      foreach ( $this->setting_skip_conditions as $filters ) {
        if ( !array_key_exists($filters, $filtered_users[$key]) || !isset($filtered_users[$key][$filters]) ) {
          $this->logger->info($filtered_users[$key]['alias'] . ' NOT validated due to Skip Condition ($filters)');
          return FALSE;
        }
      }
    }

    # exclusion based on the type and content of filter-users.json
    if ( $this->filter_users['type'] === 'whitelist') {
      if ( array_search($filtered_users[$key]['alias'], $this->filter_users['members']) === FALSE ) {
        $this->logger->info($filtered_users[$key]['alias'] . ' NOT validated due to Whitelist');
        return FALSE;
      }
    } elseif ( $this->filter_users['type'] === 'blacklist' ) {
      if ( array_search($filtered_users[$key]['alias'], $this->filter_users['members']) !== FALSE ) {
        $this->logger->info($filtered_users[$key]['alias'] . ' NOT validated due to Blacklist');
        return FALSE;
      }
    }

    if ( !empty($this->setting_email_filter) ) {
      foreach ( $this->setting_email_filter as $emails ) {
        if ( $filtered_users[$key]['alias'] == $emails ) {
          $this->logger->info($filtered_users[$key]['alias'] . ' validated.');
          return TRUE;
        }
      }

      $this->logger->info($filtered_users[$key]['alias'] . ' NOT validated due to Filter');
      return FALSE;
    }

    $this->logger->info($filtered_users[$key]['alias'] . ' validated.');
    return TRUE;
  }

  public function generateSigFromTemplate($user_info) {
    $this->logger->info("Generating signature for alias " . $user_info['alias']);
    $custom_info = '';
    $this->current_email_signature = $this->setting_email_template;

    if ( !empty($user_info['title']) ) {
      $custom_info = $user_info['title'] . '<br>';
      unset($user_info['title']);
    }
    $custom_info .= '<br>';
    if ( !empty($user_info['phone0']) ) {
      $custom_info .= $user_info['phone0'] . '<br>';
      unset($user_info['phone0']);
    }
    if ( !empty($user_info['alias']) ) {
      $custom_info .= $user_info['alias'] . '<br>';
      unset($user_info['alias']);
    }
    if ( !empty($user_info['websites']) ) {
      $custom_info .= $user_info['websites'] . '<br>';
      unset($user_info['websites']);
    }
    $this->current_email_signature = str_replace('{{customInfo}}', $custom_info, $this->current_email_signature);

    if ( !empty($user_info['address']) ) {
      $address = $this->addresses[$user_info['address']];
    } else {
      $address = $this->addresses[0];
    }
    $this->current_email_signature = str_replace('{{address}}', $address, $this->current_email_signature);

    foreach ( $user_info as $key => $value ) {
      $this->current_email_signature = str_replace('{{' . $key . '}}', $value, $this->current_email_signature);
    }

    if ( $this->setting_remove_blanks == TRUE ) {
      $this->current_email_signature = preg_replace("/\{\{[^}\}]+\}\}/", "", $this->current_email_signature);
    }

    return $this;
  }


  # Remove or not empty fields from template
  public function addSettingStripBlanks($value) {
    $this->logger->info('Setting REMOVE_BLANKS to ' . var_export($value, TRUE));

    $this->setting_remove_blanks = $value;

    return $this;
  }

  # Check if any keys within array are missing - Skip updating these signatures
  public function addSettingSkipConditions($value) {
    if ( !is_array($value) ) {
      $this->logger->warning("No Skip Conditions were set as the provided value ($value) was not an array");
      return $this;
    }

    $this->logger->info("Setting Skip Conditions to ", $value);
    $this->setting_skip_conditions = $value;

    return $this;
  }

  # Set a new template located in the "signatures" directory
  public function addSettingSetTemplate($value) {
    $this->logger->info("Setting the template $value");

    $this->setting_email_template = file_get_contents($this->setting_signature_path . $value);

    return $this;
  }

  public function getManualOverrides(array $filtered_users): array {
    if ( !empty($this->overrides) ) {
      $this->logger->info('Setting the manual overrides from the ' . self::OVERRIDES . ' file');
      $available_fields = $this->getMergeFields();
      $local_overrides = $this->overrides;

      foreach ( $local_overrides as $alias => $fields ) {
        if ( !isset($filtered_users[$alias]) ) {
          unset($local_overrides[$alias]);
          $this->logger->warning('Alias Email ' . $alias . 'is not valid, so it has been ignored');
        }

        foreach ( $fields as $field => $value ) {
          if ( !in_array($field, array_merge($available_fields, ['alreadyUpdated', 'address'])) && !empty($available_fields) ) {
            unset($local_overrides[$alias][$field]);
            $this->logger->warning('The field ' . $field . ' is not a valid field, so it has been ignored');
          }
        }

        if ( isset($fields['alreadyUpdated']) ) {
          unset($fields['alreadyUpdated']);
          if ( isset($local_overrides[$alias]) ) {
            if ( empty(array_diff(array_keys($fields), $local_overrides[$alias]['alreadyUpdated'])) ) {
              unset($local_overrides[$alias]);
            }

            unset($local_overrides[$alias]['alreadyUpdated']);
          }
        }
      }
    } else {
      $this->logger->info(self::OVERRIDES . ' file is empty');
      return [];
    }

    $this->logger->debug('The manual overrides: ', $local_overrides);
    return $local_overrides;
  }

  public function addSettingRunTestMode($value) {
    $this->logger->info('TEST->' . var_export($value, TRUE));
    $this->setting_testing_mode = $value;

    return $this;
  }

  # Preview the email template that has been generated
  public function addSettingPreviewSignature($value) {
    $this->logger->info('PREVIEW->' . var_export($value, TRUE));
    $this->setting_preview_template = $value;

    return $this;
  }

  public function addSettingFilterEmailsToUpdate($value) {
    $this->logger->info('FILTER->', $value);
    $this->setting_email_filter = $value;

    return $this;
  }

  public function processUsersList($raw_users_list) {
    $this->logger->info('Processing the raw GSuite user list');
    $filtered_users = [];

    # filter out unneeded data
    foreach ( $raw_users_list['users'] as $user ) {
      $filtered_users = array_merge($filtered_users, $this->functionStripUserAttributes($user));
    }

    # refresh the current saved list
    $this->logger->info('Refreshing ' . self::USER_DATA . ' with the info we just got from GSuite');
    file_put_contents($this->setting_json_path . self::USER_DATA, json_encode($filtered_users, JSON_PRETTY_PRINT));

    # apply overrides, if any
    $overrides = $this->getManualOverrides($filtered_users);

    if ( !empty($overrides) ) {
      foreach ( $overrides as $alias => $keys ) {
        $filtered_users[$alias] = array_merge($filtered_users[$alias], $keys);
      }
    }

    $this->logger->debug('The aliases list, after processing: ', $filtered_users);
    return $filtered_users;
  }

  public function getUsersDiff(array $filtered_users): array {
    $need_sig_update = [];
    foreach ( $filtered_users as $key => $value ) {
      if ( isset($this->previous_user_data[$key]) ) {
        $diff = array_diff_assoc($value, $this->previous_user_data[$key]);
        if ( !empty($diff) ) {
          $need_sig_update[] = $value['alias'];
        }
      } else {
        $need_sig_update[] = $value['alias'];
      }
    }

    $result = array_unique($need_sig_update);
    if ( empty($result) ) {
      $this->logger->info('There are no aliases that need their signatures updated');
    } else {
      $this->logger->info('The following aliases need their signatures updated: ', $result);
    }

    return $result;
  }

  public function reapplyOverridesToNewData(array $filtered_users, array $need_sig_update): array {
    $this->logger->info('Reapplying manual overrides for aliases need updating due to fresh GSuite data');
    foreach ( $need_sig_update as $alias ) {
      if ( isset($this->overrides[$alias]) ) {
        $this->logger->debug('Reapplying manual override for: ', $alias);
        $filtered_users[$alias] = array_merge($filtered_users[$alias], $this->overrides[$alias]);
        unset($filtered_users[$alias]['alreadyUpdated']);
      }
    }

    return $filtered_users;
  }

  public function buildSigGroups(array $need_sig_update, array $filtered_users): array {
    $this->logger->info('Building signature groups');
    $sig_groups = [];
    foreach ( $need_sig_update as $alias ) {
      if ( !empty($filtered_users[$alias]['accountType']) && $filtered_users[$alias]['accountType'] == self::GENERIC ) {
        $sig_groups['plus-generic'][] = $alias;
      } elseif ( !empty($filtered_users[$alias]['thumbnailPhotoUrl']) && !preg_match("/photos\/private/", $filtered_users[$alias]['thumbnailPhotoUrl']) ) {
        $sig_groups['plus'][] = $alias;
      } else {
        $sig_groups['plus-no-photo'][] = $alias;
      }
    }

    $this->logger->debug("The signature groups: ", $sig_groups);
    return $sig_groups;
  }

  private function markOverridesAsDone(array $user) {
    if ( $this->setting_testing_mode === FALSE && !empty($this->overrides) ) {
      if ( isset($this->overrides[$user['alias']]) ) {
        $filtered_copy = $this->overrides;
        unset($filtered_copy[$user['alias']]['alreadyUpdated']);
        $this->overrides[$user['alias']]['alreadyUpdated'] = array_keys($filtered_copy[$user['alias']]);
      }
    }
  }
}
