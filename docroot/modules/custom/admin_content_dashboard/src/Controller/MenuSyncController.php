<?php

namespace Drupal\admin_content_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Url;
use Drupal\Core\Link;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class MenuSyncController.
 *
 * @package Drupal\admin_content_dashboard\Controller
 */
class MenuSyncController extends ControllerBase {

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
   * Retrieve menu links content and display it in a table.
   */
  public function menuContentList() {
    // Query to get the required fields from menu_link_content_data table.
    $query = Database::getConnection()->select('menu_link_content_data', 'mlc')
      ->fields('mlc', ['id', 'title', 'bundle', 'menu_name', 'link__uri'])
      ->condition('enabled', 1)
      ->orderBy('id', 'DESC'); // Only get enabled menu items.

    // Execute the query and fetch results.
    $results = $query->execute()->fetchAll();

    // Load config settings from custom module.
    $config = $this->configFactory->get('config_sync.settings');
    // Get remote sites configuration from config.
    $remote_sites = $config->get('remote_sites') ?? [];

    // Build the table header.
    $header = [
      'id' => $this->t('Menu ID'),
      'title' => $this->t('Title'),
      'menu_name' => $this->t('Menu Type'),
      'send' => $this->t('Schedule for Menu Sync'),
    ];

    $rows = [];
    foreach ($results as $row) {
      // Always list the menu links.
      $rows[] = [
        'id' => $row->id,
        'title' => $row->title,
        'menu_name' => $row->menu_name,
        'send' => Link::fromTextAndUrl($this->t('Schedule for Menu Sync'), Url::fromRoute('admin_content_dashboard.send_menu', ['id' => $row->id])),
      ];
    }

    $build = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No menu links available.'),
    ];

    return $build;
  }

  /**
   * Add the selected menu link to the synchronization queue.
   */
  public function sendToRemote($id) {
    // Load the menu link.
    $menu_link = Database::getConnection()->select('menu_link_content_data', 'mlc')
      ->fields('mlc', ['id', 'title', 'bundle', 'menu_name', 'link__uri'])
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    if (!$menu_link) {
      \Drupal::messenger()->addError($this->t('Menu link not found.'));
      return $this->redirect('admin_content_dashboard.menu_content_list');
    }

    $config = $this->configFactory->get('config_sync.settings');
    $remote_sites = $config->get('remote_sites') ?? [];

    foreach ($remote_sites as $site) {
      // Insert into the queue instead of sending directly.
      if(in_array($menu_link->menu_name, $site['menus'])) {
        
      Database::getConnection()->insert('synchronisation_queue')
        ->fields([
          'nid' => $menu_link->id,  // Using 'id' for the menu link.
          'title' => $menu_link->title,
          'remote_site' => $site['site_name'],
          'operation' => 'create',  // Assuming create operation.
          'status' => 'awaiting',
          'created' => time(),
          'entity_type' => 'menu',
        ])
        ->execute();

      \Drupal::messenger()->addMessage($this->t('Menu link added to queue for @site.', ['@site' => $site['site_name']]));
      }
    }

    return $this->redirect('admin_content_dashboard.menu_content_list');
  }

  public function pushMenu($qid) {
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
  
    // Synchronize menu link content.
    $menu_link = Database::getConnection()->select('menu_link_content_data', 'mlc')
        ->fields('mlc', ['id', 'title', 'bundle', 'menu_name', 'link__uri'])
        ->condition('id', $queue_item->nid)
        ->execute()
        ->fetchObject();
  
    if ($menu_link) {
        // Prepare the data to be sent to the remote site.
        $data = [
            'id' => $menu_link->id,
            'title' => $menu_link->title,
            'bundle' => $menu_link->bundle,
            'menu_name' => $menu_link->menu_name,
            'link_uri' => $menu_link->link__uri,
        ];
  
        // Get remote site details.
        $config = \Drupal::config('config_sync.settings');
        $remote_sites = $config->get('remote_sites');
  
        foreach ($remote_sites as $site) {
            if ($site['site_name'] == $queue_item->remote_site) {
                $username = $site['username'];
                $password = $site['password'];
  
                // Log the data being sent.
                \Drupal::logger('push_menu')->debug('Pushing menu data: @data', ['@data' => print_r($data, TRUE)]);
  
                // Send the POST request to synchronize the menu link.
                $response = $this->sendPostRequest($site['url'], $data, $username, $password, $queue_item->nid, $queue_item->remote_site);
  
                // Update the synchronization status based on the response.
                $this->updateSynchronizationStatus($response, $queue_item);
            }
        }
    } else {
        \Drupal::messenger()->addError($this->t('Menu link not found.'));
    }
  
    return $this->redirect('admin_content_dashboard.queue');
  }
  
  public function updateSynchronizationStatus($response, $queue_item) {
    if ($response['success']) {
        // Remove the synchronized item from the queue.
        Database::getConnection()->delete('synchronisation_queue')
            ->condition('qid', $queue_item->qid)
            ->execute();
        
        \Drupal::messenger()->addMessage($this->t('Menu link successfully pushed to @site.', ['@site' => $queue_item->remote_site]));
    } else {
        \Drupal::messenger()->addError($this->t('Failed to push menu link to @site. Check logs for details.', ['@site' => $queue_item->remote_site]));
    }
}


  /**
   * Send POST request to the remote site to create the menu.
   */
  public function sendPostRequest($url, $data, $username, $password, $id, $remote_site_name) {
    $client = \Drupal::httpClient();

    // Load the menu link object.
    $menu_link = Database::getConnection()->select('menu_link_content_data', 'mlc')
      ->fields('mlc', ['id', 'title', 'bundle', 'menu_name', 'link__uri'])
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    if (!$menu_link) {
      return ['success' => false, 'message' => 'Menu link not found.'];
    }

    // Prepare the data to be sent to the remote site.
    $data = [
      'id' => $menu_link->id,
      'title' => $menu_link->title,
      'bundle' => $menu_link->bundle,
      'menu_name' => $menu_link->menu_name,
      'link__uri' => $menu_link->link__uri,
    ];

    try {
      $response = $client->post($url . '/api/receive-menu-link', [
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

        if (!empty($responseData['remote_menu_link_id'])) {
          $remote_menu_link_id = $responseData['remote_menu_link_id'];

          // Store the relation of synchronization in a new table.
          Database::getConnection()->insert('relation_of_synchronisation')
            ->fields([
              'nid' => $id,
              'title' => $menu_link->title,
              'remote_nid' => $remote_menu_link_id,
              'remote_site' => $remote_site_name,
              'operation_date' => time(),
              'content_type' => 'link',
              'entity_type' => 'menu',
            ])
            ->execute();

          return ['success' => true, 'remote_menu_link_id' => $remote_menu_link_id];
        }

        return ['success' => false, 'message' => 'No Remote Menu Link ID returned from the child site.'];
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