<?php

namespace Drupal\site_dashboard\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CreateSiteForm extends FormBase {

  protected $fileSystem;

  public function __construct(FileSystemInterface $file_system) {
    $this->fileSystem = $file_system;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_system')
    );
  }

  public function getFormId() {
    return 'create_site_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $site_count = $form_state->get('site_count');
    if ($site_count === NULL) {
      $site_count = 1;
      $form_state->set('site_count', $site_count);
    }

    $form['sites'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Sites'),
      '#prefix' => '<div id="sites-wrapper">',
      '#suffix' => '</div>',
    ];

    for ($i = 0; $i < $site_count; $i++) {
      $form['sites']['site_name_' . $i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Site Name @number', ['@number' => $i + 1]),
        '#required' => ($i === 0) ? TRUE : FALSE, // Only the first field is required.
      ];
    }

    // $form['sites']['add_site'] = [
    //   '#type' => 'submit',
    //   '#value' => $this->t('Add more sites'),
    //   '#submit' => ['::addMoreSite'],
    //   '#ajax' => [
    //     'callback' => '::addmoreCallback',
    //     'wrapper' => 'sites-wrapper',
    //   ],
    // ];

    // if ($site_count > 1) {
    //   $form['sites']['remove_site'] = [
    //     '#type' => 'submit',
    //     '#value' => $this->t('Remove last site'),
    //     '#submit' => ['::removeSite'],
    //     '#ajax' => [
    //       'callback' => '::addmoreCallback',
    //       'wrapper' => 'sites-wrapper',
    //     ],
    //   ];
    // }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Site'),
    ];

    return $form;
  }

  // public function addMoreSite(array &$form, FormStateInterface $form_state) {
  //   $site_count = $form_state->get('site_count');
  //   $form_state->set('site_count', $site_count + 1);
  //   $form_state->setRebuild(TRUE);
  // }

  // public function removeSite(array &$form, FormStateInterface $form_state) {
  //   $site_count = $form_state->get('site_count');
  //   if ($site_count > 1) {
  //     $form_state->set('site_count', $site_count - 1);
  //     $form_state->setRebuild(TRUE);
  //   }
  // }

  // public function addmoreCallback(array &$form, FormStateInterface $form_state) {
  //   return $form['sites'];
  // }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $site_count = $form_state->get('site_count');
    $sites_directory = $this->fileSystem->realpath('sites');
  
    for ($i = 0; $i < $site_count; $i++) {
      $site_name = $form_state->getValue('site_name_' . $i);
      $new_site_path = $sites_directory . '/' . $site_name;
  
      if ($this->fileSystem->mkdir($new_site_path, 0755)) {
        $default_settings = $sites_directory . '/default/default.settings.php';
  
        if (file_exists($default_settings)) {
          $this->fileSystem->copy($default_settings, $new_site_path . '/settings.php');
          $this->messenger()->addStatus($this->t('Site directory @site created successfully.', ['@site' => $site_name]));
  
          $files_directory = $new_site_path . '/files';
          if ($this->fileSystem->mkdir($files_directory, 0755)) {
            $this->messenger()->addStatus($this->t('Empty "files" directory created successfully.'));
          } else {
            $this->messenger()->addError($this->t('Failed to create "files" directory.'));
          }
  
          // Update the sites.php file
          $sites_php_path = $sites_directory . '/sites.php';
          $site_route = "\$sites['" . $site_name . "'] = '" . $site_name . "';\n";
  
          if (file_exists($sites_php_path)) {
            if (file_put_contents($sites_php_path, $site_route, FILE_APPEND | LOCK_EX)) {
              $this->messenger()->addStatus($this->t('Site route added to sites.php for @site.', ['@site' => $site_name]));
            } else {
              $this->messenger()->addError($this->t('Failed to update sites.php for @site.', ['@site' => $site_name]));
            }
          } else {
            $this->messenger()->addError($this->t('sites.php file not found.'));
          }
  
        } else {
          $this->messenger()->addError($this->t('Default settings file not found.'));
        }
      } else {
        $this->messenger()->addError($this->t('Failed to create site directory @site.', ['@site' => $site_name]));
      }
    }
  }
}
