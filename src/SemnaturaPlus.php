<?php

namespace Moometric;

/**
 * GSuite GMail Signature Manager
 *
 * @author MooMaster / Gabriel Buta
 */
class SemnaturaPlus {

  const ADMIN_EMAIL = 'user@domain.com';
  const DOMAIN = 'domain.com';

  // Object variables updated by functions
  public $user_email;
  public $user_alias;
  public $current_email_signature;
  public $previous_user_data = [];
  public $filter_users = [];
  public $overrides = [];
  // Google services that need to be instantiated
  public $google_client;
  public $google_service_directory;
  public $google_service_gmail;
  public $google_service_gmail_send_as;
  // Setting variables and arrays
  public $setting_remove_blanks = TRUE;
  public $setting_email_template;
  // Filter settings
  public $setting_skip_conditions = [];
  public $setting_email_filter = [];
  public $setting_testing_mode = FALSE;
  public $setting_preview_template = TRUE;
  // Path settings for local variables/templates
  public $setting_json_path = __DIR__ . '/../local_vars/';
  public $setting_signature_path = __DIR__ . '/../signatures/';
  public $setting_service_account_path = __DIR__ . '/../local_vars/';

  public function __construct() {
    if ( file_exists($this->setting_service_account_path . 'service-account.json') ) {
      $this->setting_service_account_path = 'GOOGLE_APPLICATION_CREDENTIALS=' . $this->setting_service_account_path . 'service-account.json';
      putenv($this->setting_service_account_path);
      SemnaturaPlus::googleClientConnect();
    }
    if ( file_exists($this->setting_signature_path . 'plus.html') ) {
      $this->setting_email_template = file_get_contents($this->setting_signature_path . 'plus.html');
    }

    $content = file_get_contents($this->setting_json_path . 'user-data.json');
    if ( !empty($content) ) {
      $this->previous_user_data = json_decode($content, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        echo "JSON data from user-data.json is incorrect:" . json_last_error_msg();
        exit;
      }
    }

    $content = file_get_contents($this->setting_json_path . 'filter-users.json');
    if ( !empty($content) ) {
      $this->filter_users = json_decode($content, TRUE);
      if ( json_last_error() !== JSON_ERROR_NONE ) {
        echo "JSON data from filter-users.json is incorrect:" . json_last_error_msg();
        exit;
      }
    }

    $content = file_get_contents($this->setting_json_path . 'overrides.json');
    if ( !empty($content) ) {
      $this->overrides = json_decode($content, TRUE);
      if ( json_last_error() !== JSON_ERROR_NONE ) {
        echo "JSON data from overrides.json is incorrect:" . json_last_error_msg();
        exit;
      }
    }

    // clean-up any left-over fields in alreadyUpdated tags
    foreach ( $this->overrides as $alias => $fields ) {
      if ( isset($fields['alreadyUpdated']) ) {
        foreach ( $fields['alreadyUpdated'] as $key => $field ) {
          if ( !isset($fields[$field]) ) {
            unset($this->overrides[$alias]['alreadyUpdated'][$key]);
            echo "\nCleaned-up left-over key (" . $field . ") in the field (alreadyUpdated) of " . $alias . " from overrides.json\n";
          }
        }
      }
    }
  }

// Core Google API Client functions
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
      echo "Must set the full path to service-account.json file - Cannot find file $this->setting_service_account_path";
    }

    return $this;
  }

  public function getUsersList() {
    $this->google_client->setSubject(self::ADMIN_EMAIL);

    return $this->google_service_directory->users->listUsers(Array('domain' => (string)self::DOMAIN, "projection" => "full", "maxResults" => 500));
  }

  public function getUser(): object {
    $this->google_client->setSubject(self::ADMIN_EMAIL);
    $optParams = array("projection" => "full");

    return $this->google_service_directory->users->get("$this->user_email", $optParams);
  }

  public function getUserAlias(): array {
    $alias_array = [];
    $this->google_client->setSubject("$this->user_email");
    $response = $this->google_service_gmail->users_settings_sendAs->listUsersSettingsSendAs($this->user_email);
    foreach ( $response as $key ) {
      $alias_array[] = $key['sendAsEmail'];
    }

    return $alias_array;
  }

  public function getUserSignature() {
    $this->google_client->setSubject("$this->user_email");
    $this->current_email_signature = $this->google_service_gmail->users_settings_sendAs->get($this->user_email, $this->user_alias)->getSignature();

    return $this;
  }

  public function setUserSignature() {
    $response = [];
    if ( $this->setting_testing_mode == TRUE ) {
      echo "<h4>Currently in testing mode - no signature will be updated for alias $this->user_alias</h4>";
    } else {
      $this->google_client->setSubject($this->user_email);
      $this->google_service_gmail_send_as->setSignature($this->current_email_signature);
      $response = $this->google_service_gmail->users_settings_sendAs->patch($this->user_email, $this->user_alias, $this->google_service_gmail_send_as);
    }
    if ( $this->setting_preview_template == TRUE ) {
      echo $this->current_email_signature;
    }

    return $response;
  }

// Core user based actions

  public function updateSignatures(array $filtered_users) {
    foreach ( $filtered_users as $key => $value ) {
      if ( SemnaturaPlus::functionValidateUsers($key, $filtered_users) == FALSE ) {
        continue;
      }
      SemnaturaPlus::generateTemplate($filtered_users[$key]);
      $this->user_email = $filtered_users[$key]['primaryEmail'];
      $this->user_alias = $filtered_users[$key]['alias'];
      SemnaturaPlus::setUserSignature();
      $this->markOverridesAsDone($filtered_users[$key]);
    }

    // refresh the overrides
    file_put_contents($this->setting_json_path . 'overrides.json', json_encode($this->overrides, JSON_PRETTY_PRINT));

    return $this;
  }

  public function getMergeFields(): array {
    $all_fields = [];
    foreach ( $this->previous_user_data as $alias => $fields ) {
      $all_fields = array_merge($all_fields, $fields);
    }

    return array_keys($all_fields);
  }

//Manipulate data functions

  private function functionStripUserAttributes($user_array): array {
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
    return $filtered_users;
  }

  public function functionValidateUsers(string $key, array $filtered_users) {
    if ( !empty($this->setting_skip_conditions) ) {
      foreach ( $this->setting_skip_conditions as $filters ) {
        if ( !array_key_exists($filters, $filtered_users[$key]) || !isset($filtered_users[$key][$filters]) ) {
          return FALSE;
        }
      }
    }

    // exclusion based on the type and content of filter-users.json
    if ( $this->filter_users['type'] === 'whitelist') {
      if ( array_search($filtered_users[$key]['alias'], $this->filter_users['members']) === FALSE ) {
        return FALSE;
      }
    } elseif ( $this->filter_users['type'] === 'blacklist' ) {
      if ( array_search($filtered_users[$key]['alias'], $this->filter_users['members']) !== FALSE ) {
        return FALSE;
      }
    }

    if ( !empty($this->setting_email_filter) ) {
      foreach ( $this->setting_email_filter as $emails ) {
        if ( $filtered_users[$key]['alias'] == $emails ) {
          return TRUE;
        }
      }

      return FALSE;
    }

    return TRUE;
  }

  public function generateTemplate($user_info) {
    var_dump($user_info);
    $custom_info = '';
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

    $this->current_email_signature = $this->setting_email_template;
    $this->current_email_signature = str_replace('{{customInfo}}', $custom_info, $this->current_email_signature);
    foreach ( $user_info as $key => $value ) {
      $this->current_email_signature = str_replace('{{' . $key . '}}', $value, $this->current_email_signature);
    }

    if ( $this->setting_remove_blanks == TRUE ) {
      $this->current_email_signature = preg_replace("/\{\{[^}\}]+\}\}/", "", $this->current_email_signature);
    }

    return $this;
  }

// Settings functions

  public function addSettingStripBlanks($value) {
    // Remove empty fields from template
    $this->setting_remove_blanks = $value;

    return $this;
  }

  public function addSettingManualUserEmail($user_email, $user_alias = "default@default.com") {
    // Manually set the user the update
    $this->user_email = $user_email;
    $this->user_alias = $user_alias;

    return $this;
  }

  public function addSettingManualSetSignature($value) {
    // Manually set the Signature
    $this->current_email_signature = $value;
  }

  public function addSettingSkipConditions($value) {
    // Check if any keys within array are missing - Skip updating these signatures
    $this->setting_skip_conditions = $value;

    return $this;
  }

  public function addSettingSetTemplate($value) {
    // Set a new template located in the "signatures" directory
    $this->setting_email_template = file_get_contents($this->setting_signature_path . $value);

    return $this;
  }

  public function getManualOverrides(array $filtered_users): array {
    if ( !empty($this->overrides) ) {
      $available_fields = $this->getMergeFields();
      $local_overrides = $this->overrides;

      foreach ( $local_overrides as $alias => $fields ) {
        if ( !isset($filtered_users[$alias]) ) {
          unset($local_overrides[$alias]);
          echo "<p>Alias Email " . $alias . "is not valid, so it has been ignored</p>";
        }

        foreach ( $fields as $field => $value ) {
          if ( !in_array($field, $available_fields) && $field != 'alreadyUpdated' && !empty($available_fields) ) {
            unset($local_overrides[$alias][$field]);
            echo("<p>The field " . $field . " is not a valid field, so it has been ignored<p>");
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
      echo "\n overrides.json file empty. \n";
      return [];
    }

    return $local_overrides;
  }

  public function addSettingRunTestMode($value) {
    // Run in test mode only - will echo output but not update signatures. Default is false
    $this->setting_testing_mode = $value;

    return $this;
  }

  public function addSettingPreviewSignature($value) {
    // Preview the email template that has been generated
    $this->setting_preview_template = $value;

    return $this;
  }

  public function addSettingFilterEmailsToUpdate($value) {
    $this->setting_email_filter = $value;

    return $this;
  }

  public function addSettingUnsetFilters() {
    unset($this->setting_email_filter);
    unset($this->setting_skip_conditions);
    $this->setting_email_filter = [];
    $this->setting_skip_conditions = [];

    return $this;
  }

  public function addSettingServiceAccountPath($value) {
    if ( strpos($value, 'service-account.json') == TRUE ) {
      $this->setting_service_account_path = 'GOOGLE_APPLICATION_CREDENTIALS=' . $value;
    } else {
      $this->setting_service_account_path = 'GOOGLE_APPLICATION_CREDENTIALS=' . $value . 'service-account.json';
    }
    SemnaturaPlus::googleClientConnect();

    return $this;
  }

  public function addSettingJSONPath($value) {
    $this->setting_json_path = $value;

    return $this;
  }

  public function addSettingSignaturePath($value) {
    $this->setting_signature_path = $value;

    return $this;
  }

  public function processUsersList($raw_users_list) {
    $filtered_users = [];

    // filter out unneeded data
    foreach ( $raw_users_list['users'] as $user ) {
      $filtered_users = array_merge($filtered_users, $this->functionStripUserAttributes($user));
    }

    // refresh the current saved list
    file_put_contents($this->setting_json_path . 'user-data.json', json_encode($filtered_users, JSON_PRETTY_PRINT));

    // apply overrides, if any
    $overrides = $this->getManualOverrides($filtered_users);

    if ( !empty($overrides) ) {
      foreach ( $overrides as $alias => $keys ) {
        $filtered_users[$alias] = array_merge($filtered_users[$alias], $keys);
      }
    }

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

    return array_unique($need_sig_update);
  }

  public function reapplyOverridesToNewData(array $filtered_users, array $need_sig_update): array {
    foreach ( $need_sig_update as $alias ) {
      if ( isset($this->overrides[$alias]) ) {
        $filtered_users[$alias] = array_merge($filtered_users[$alias], $this->overrides[$alias]);
        unset($filtered_users[$alias]['alreadyUpdated']);
      }
    }

    return $filtered_users;
  }

  public function buildSigGroups(array $need_sig_update, array $filtered_users): array {
    $sig_groups = [];
    foreach ( $need_sig_update as $primary_email ) {
      if ( !empty($filtered_users[$primary_email]['thumbnailPhotoUrl']) ) {
        $sig_groups['plus'][] = $primary_email;
      } else {
        $sig_groups['plus-no-photo'][] = $primary_email;
      }
    }

    return $sig_groups;
  }

  private function markOverridesAsDone(array $user) {
    if ( $this->setting_testing_mode === TRUE && !empty($this->overrides) ) {
      if ( isset($this->overrides[$user['alias']]) ) {
        $filtered_copy = $this->overrides;
        unset($filtered_copy[$user['alias']]['alreadyUpdated']);
        $this->overrides[$user['alias']]['alreadyUpdated'] = array_keys($filtered_copy[$user['alias']]);
      }
    }
  }
}
