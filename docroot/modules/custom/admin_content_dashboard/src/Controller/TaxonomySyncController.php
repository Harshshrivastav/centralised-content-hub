<?php

namespace Drupal\admin_content_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Core\Database\Database;
use Drupal\Core\Url;
use Drupal\Core\Link;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class TaxonomySyncController.
 *
 * @package Drupal\admin_content_dashboard\Controller
 */
class TaxonomySyncController extends ControllerBase {

  protected $configFactory;

  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->configFactory = $configFactory;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * Retrieve taxonomy terms and display them in a table.
   */
  public function taxonomyList() {
    // Query to get the required fields from taxonomy_term_field_data.
    $query = Database::getConnection()->select('taxonomy_term_field_data', 'ttd')
      ->fields('ttd', ['tid', 'name', 'vid'])
      ->condition('status', 1);  // Only get active taxonomy terms.

    // Execute the query and fetch results.
    $results = $query->execute()->fetchAll();

    // Load config settings from custom module.
    $config = $this->configFactory->get('config_sync.settings');
    // Get remote sites configuration from config.
    $remote_sites = $config->get('remote_sites') ?? [];

    // Build the table header.
    $header = [
      'tid' => $this->t('Taxonomy Term ID'),
      'name' => $this->t('Name'),
      'vocabulary' => $this->t('Vocabulary'),
      'send' => $this->t('Schedule for Taxomomy Sync'),
    ];

    $rows = [];
    foreach ($results as $row) {
      // Always list the taxonomy terms.
      $rows[] = [
        'tid' => $row->tid,
        'name' => $row->name,
        'vocabulary' => $row->vid,
        'send' => Link::fromTextAndUrl($this->t('Schedule'), Url::fromRoute('admin_content_dashboard.send_taxonomy', ['tid' => $row->tid])),
      ];
    }

    $build = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No taxonomy terms available.'),
    ];

    return $build;
  }

  /**
   * Send the taxonomy term to the remote site.
   */
  public function sendToRemote($tid) {
    // Load the taxonomy term from the queue.
    $term = Database::getConnection()->select('taxonomy_term_field_data', 'ttd')
      ->fields('ttd', ['tid', 'name', 'vid'])
      ->condition('tid', $tid)
      ->execute()
      ->fetchObject();

      if (!$term) {
        \Drupal::messenger()->addError($this->t('Taxonomy term not found.'));
        return $this->redirect('admin_content_dashboard.taxonomy_list');
      }
  
      $config = $this->configFactory->get('config_sync.settings');
      $remote_sites = $config->get('remote_sites') ?? [];

  
      foreach ($remote_sites as $site) {
        // Insert into the queue instead of sending directly.
        if(in_array($term->vid, $site['vocabularies'])) {
          
        
        Database::getConnection()->insert('synchronisation_queue')
          ->fields([
            'nid' => $term->tid,  // Using 'tid' for the taxonomy term.
            'title' => $term->name,
            'remote_site' => $site['site_name'],
            'operation' => 'create',  // Assuming create operation.
            'status' => 'awaiting',
            'created' => time(),
            'entity_type' => 'taxonomy_term',
          ])
          ->execute();
        \Drupal::messenger()->addMessage($this->t('Taxonomy term sent to @site.', ['@site' => $site['site_name']]));
    }
  }

    return $this->redirect('admin_content_dashboard.taxonomy_list');
  }

  public function pushTaxonomy($qid) {
    // Load the queue item from the database.
    $queue_item = Database::getConnection()->select('synchronisation_queue', 'q')
        ->fields('q', ['qid', 'nid', 'remote_site', 'operation', 'language'])
        ->condition('qid', $qid)
        ->execute()
        ->fetchObject();
  
    if (!$queue_item) {
        \Drupal::messenger()->addError($this->t('Queue item not found.'));
        return $this->redirect('admin_content_dashboard.queue');
    }
  
    // Load the taxonomy term from the database.
    $term = Database::getConnection()->select('taxonomy_term_field_data', 'ttd')
        ->fields('ttd', ['tid', 'name', 'vid'])
        ->condition('tid', $queue_item->nid) // Using 'nid' for taxonomy term ID.
        ->execute()
        ->fetchObject();
  
    if ($term) {
        // Load the vocabulary to get its label.
        $vocabulary = Vocabulary::load($term->vid);
        $vocabulary_name = $vocabulary ? $vocabulary->label() : '';
  
        // Prepare the data to send to the remote site.
        $data = [
            'tid' => $term->tid,
            'name' => $term->name,
            'vid' => $term->vid,
            'vocabulary' => $vocabulary_name,
        ];
  
        // Get remote site details from config.
        $config = \Drupal::config('config_sync.settings');
        $remote_sites = $config->get('remote_sites');
  
        foreach ($remote_sites as $site) {
            if ($site['site_name'] == $queue_item->remote_site) {
                $username = $site['username'];
                $password = $site['password'];
  
                // Log the taxonomy data being sent.
                \Drupal::logger('push_taxonomy')->debug('Pushing taxonomy term data: @data', ['@data' => print_r($data, TRUE)]);
  
                // Send POST request to synchronize the taxonomy term.
                $response = $this->sendPostRequest($site['url'], $data, $username, $password, $term->tid, $queue_item->remote_site);
  
                // Update the synchronization status based on the response.
                $this->updateSynchronizationStatus($response, $queue_item);
            }
        }
    } else {
        \Drupal::messenger()->addError($this->t('Taxonomy term not found.'));
    }
  
    return $this->redirect('admin_content_dashboard.queue');
  }
  
  public function updateSynchronizationStatus($response, $queue_item) {
    if ($response['success']) {
        // Remove the synchronized item from the queue.
        Database::getConnection()->delete('synchronisation_queue')
            ->condition('qid', $queue_item->qid)
            ->execute();
        
        \Drupal::messenger()->addMessage($this->t('Taxonomy term successfully pushed to @site.', ['@site' => $queue_item->remote_site]));
    } else {
        \Drupal::messenger()->addError($this->t('Failed to push taxonomy term to @site. Check logs for details.', ['@site' => $queue_item->remote_site]));
    }
  }

  /**
   * Send POST request to the remote site to create the taxonomy term.
   */
  public function sendPostRequest($url, $data, $username, $password, $tid, $remote_site_name) {
    $client = \Drupal::httpClient();
    $term = Database::getConnection()->select('taxonomy_term_field_data', 'ttd')
    ->fields('ttd', ['tid', 'name', 'vid'])
    ->condition('tid', $tid)
    ->execute()
    ->fetchObject();

    $vocabulary = Vocabulary::load($term->vid);
    $vocabulary_name = $vocabulary->label();

    // Prepare the data to be sent to the remote site.
    $data = [
      'tid' => $term->tid,
      'name' => $term->name,
      'vid' => $term->vid,
      'vocabulary' => $vocabulary_name,
    ];

    try {
      $response = $client->post($url . '/api/taxonomy-sync', [
        'body' => json_encode($data),
        'auth' => [$username, $password],
        'verify' => false,
        'headers' => [
          'Content-Type' => 'application/json',
        ],
      ]);

      $statusCode = $response->getStatusCode();
      if ($statusCode === 200) {
        $responseData = json_decode($response->getBody()->getContents(), true);

        if (!empty($responseData['remote_tid'])) {
          $remote_tid = $responseData['remote_tid'];


          Database::getConnection()->insert('relation_of_synchronisation')
          ->fields([
            'nid' => $tid,
            'title' => $term->name,
            'remote_nid' => $remote_tid,
            'remote_site' => $remote_site_name,
            'operation_date' => time(),
            'content_type' => 'taxonomy',
          ])
          ->execute();
          return ['success' => true, 'remote_tid' => $responseData['remote_tid']];
        }

        return ['success' => false, 'message' => 'No Remote TID returned from the child site.'];
      } elseif ($statusCode === 403) {
        return ['success' => false, 'message' => 'Invalid credentials provided.'];
      } else {
        return ['success' => false, 'message' => 'Unexpected error occurred (HTTP ' . $statusCode . ').'];
      }
    } catch (RequestException $e) {
      return ['success' => false, 'message' => 'Failed to send data: ' . $e->getMessage()];
    }
  }

}