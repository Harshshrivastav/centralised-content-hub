<?php
namespace Drupal\config_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\node\Entity\NodeType;
use Drupal\system\Entity\Menu;
use Drupal\media\Entity\MediaType;
use Drupal\language\Entity\ConfigurableLanguage;

class ConfigSync extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'config_sync';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'config_sync.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Load the saved configuration.
    $config = $this->config('config_sync.settings');
  
    // Retrieve the current number of sites, or set it based on the saved configuration.
    $remote_sites = $config->get('remote_sites') ?? [];
    $num_sites = $form_state->get('num_sites') ?? count($remote_sites) ?: 1;
    $form_state->set('num_sites', $num_sites);
  
    // Add the wrapper for dynamic sites.
    $form['domains'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Manage Sites'),
      '#prefix' => '<div id="domains-wrapper">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
    ];
  
    // Loop through the number of sites to dynamically generate fields.
    for ($i = 0; $i < $num_sites; $i++) {
      $site_key = 'site_' . $i+1;
      $form['domains'][$site_key] = [
        '#type' => 'details', // Change fieldset to details for accordion behavior.
        '#title' => $this->t('Site @number', ['@number' => $i + 1]),
        '#open' => FALSE, // You can set this to FALSE if you want the accordion closed by default.
        '#tree' => TRUE,
      ];
  
      // Retrieve saved data for each site.
      $saved_site = $remote_sites[$site_key] ?? [];
  
      // Site Name field
      $form['domains'][$site_key]['site_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Site Name'),
        '#default_value' => $saved_site['site_name'] ?? '',
      ];
  
      // Site URL field
      $form['domains'][$site_key]['url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Site URL'),
        '#default_value' => $saved_site['url'] ?? '',
      ];
  
      // Site Username field
      $form['domains'][$site_key]['username'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Site Username'),
        '#default_value' => $saved_site['username'] ?? '',
      ];
  
      // Site Password field (do not populate the default value)
      $form['domains'][$site_key]['password'] = [
        '#type' => 'password',
        '#title' => $this->t('Site Password'),
        '#default_value' => '',
        '#description' => $this->t('Enter a new password to update, or leave blank to keep the current password.'),
      ];
  
      // Load languages, media types, menus, vocabularies, and content types
      // and repopulate them with saved data from the configuration.
  
      // Language options
      $languages = ConfigurableLanguage::loadMultiple();
      $language_options = [];
      foreach ($languages as $language) {
        $language_options[$language->getId()] = $language->getName();
      }
      $form['domains'][$site_key]['languages'] = [
        '#type' => 'select', // Change to 'select' for a dropdown.
        '#title' => $this->t('Languages'),
        '#options' => $language_options,
        '#default_value' => !empty($saved_site['languages']) ? $saved_site['languages'] : '',        '#empty_option' => $this->t('- Select a language -'), // Adds a default empty option.
        '#multiple' => FALSE, // Set to FALSE for single selection.
      ];
  
      // Media types
      $media_types = MediaType::loadMultiple();
      $media_options = [];
      foreach ($media_types as $media_type) {
        $media_options[$media_type->id()] = $media_type->label();
      }
      $form['domains'][$site_key]['media_types'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Media Types'),
        '#options' => $media_options,
        '#default_value' => $saved_site['media_types'] ?? [],
      ];
  
      // Menu options
      $menus = Menu::loadMultiple();
      $menu_options = [];
      foreach ($menus as $menu) {
        $menu_options[$menu->id()] = $menu->label();
      }
      $form['domains'][$site_key]['menus'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Available Menus'),
        '#options' => $menu_options,
        '#default_value' => $saved_site['menus'] ?? [],
      ];
  
      // Taxonomy vocabularies
      $vocabularies = Vocabulary::loadMultiple();
      $vocabulary_options = [];
      foreach ($vocabularies as $vocab) {
        $vocabulary_options[$vocab->id()] = $vocab->label();
      }
      $form['domains'][$site_key]['vocabularies'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Available Taxonomy Vocabularies'),
        '#options' => $vocabulary_options,
        '#default_value' => $saved_site['vocabularies'] ?? [],
      ];
  
      // Node types
      $content_types = NodeType::loadMultiple();
      $content_type_options = [];
      foreach ($content_types as $type) {
        $content_type_options[$type->id()] = $type->label();
      }
      $form['domains'][$site_key]['content_types'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Available Node Types'),
        '#options' => $content_type_options,
        '#default_value' => $saved_site['content_types'] ?? [],
      ];
  
      // Add a remove button for each site if there are multiple sites.
      if ($num_sites > 1) {
        $form['domains'][$site_key]['remove'] = [
          '#type' => 'submit',
          '#name' => 'remove_site_' . $i,
          '#value' => $this->t('Remove Site'),
          '#submit' => ['::removeSite'],
          '#ajax' => [
            'callback' => '::updateForm',
            'wrapper' => 'domains-wrapper',
          ],
        ];
      }
    }
  
    // Add a button to add more sites.
    $form['domains']['add_site'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add another site'),
      '#submit' => ['::addSite'],
      '#ajax' => [
        'callback' => '::updateForm',
        'wrapper' => 'domains-wrapper',
      ],
    ];
  
    return parent::buildForm($form, $form_state);
  }
  
  /**
   * Ajax callback to update the form when sites are added/removed.
   */
  public function updateForm(array &$form, FormStateInterface $form_state) {
    return $form['domains'];
  }

  /**
   * Submit handler for adding a new site.
   */
  public function addSite(array &$form, FormStateInterface $form_state) {
    // Increment the number of sites.
    $num_sites = $form_state->get('num_sites');
    $form_state->set('num_sites', $num_sites + 1);

    // Rebuild the form.
    $form_state->setRebuild();
  }

  /**
   * Submit handler for removing a site.
   */
  public function removeSite(array &$form, FormStateInterface $form_state) {
    // Get the triggering element (button) name and extract the index.
    $triggering_element = $form_state->getTriggeringElement();
    $site_index = str_replace('remove_site_', '', $triggering_element['#name']);

    // Reduce the number of sites.
    $num_sites = $form_state->get('num_sites');
    $form_state->set('num_sites', $num_sites - 1);

    // Remove the specific site from form values.
    $sites = $form_state->getValue('domains');
    unset($sites['site_' . $site_index]);
    $form_state->setValue('domains', $sites);

    // Rebuild the form.
    $form_state->setRebuild();
  }

/**
 * {@inheritdoc}
 */
public function submitForm(array &$form, FormStateInterface $form_state) {
  // Get all the submitted values.
  $values = $form_state->getValues();

  // Initialize the array for remote sites.
  $remote_sites = [];

  // Loop through the form's 'domains' values to build the remote sites array.
  if (isset($values['domains']) && is_array($values['domains'])) {
    foreach ($values['domains'] as $site_key => $site_data) {
      // Ensure that $site_data is an array and not an object like TranslatableMarkup.
      if (is_array($site_data)) {
        // Retrieve site information safely.
        $site_name = $site_data['site_name'] ?? '';
        $url = $site_data['url'] ?? '';
        $username = $site_data['username'] ?? '';

        // Only update the password if a new one is submitted.
        $password = !empty($site_data['password']) ? $site_data['password'] : $this->config('config_sync.settings')->get('remote_sites')[$site_key]['password'] ?? '';

        $content_types = array_filter($site_data['content_types'] ?? []);
        $vocabularies = array_filter($site_data['vocabularies'] ?? []);
        $media_types = array_filter($site_data['media_types'] ?? []);
        $menus = array_filter($site_data['menus'] ?? []);
        $languages = $site_data['languages'] ?? '';


        // Only add site to the array if it's properly filled.
        if (!empty($site_name) && !empty($url)) {
          $remote_sites[$site_key] = [
            'site_name' => $site_name,
            'url' => $url,
            'username' => $username,
            'password' => $password,
            'content_types' => $content_types,
            'vocabularies' => $vocabularies,
            'media_types' => $media_types,
            'menus' => $menus,
            'languages' => $languages,
          ];
        }
      }
    }
  }

  // Save the configuration settings.
  $this->config('config_sync.settings')
    ->set('remote_sites', $remote_sites) // Save remote sites in tree structure.
    ->save();

  // Call the parent submitForm method.
  parent::submitForm($form, $form_state);
}
}