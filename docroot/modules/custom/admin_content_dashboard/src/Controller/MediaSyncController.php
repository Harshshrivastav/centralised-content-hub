<?php

namespace Drupal\admin_content_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Class MediaSyncController.
 *
 * @package Drupal\admin_content_dashboard\Controller
 */
class MediaSyncController extends ControllerBase {

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
   * Display media entities and offer option to send them.
   */
  public function mediaList() {
    $query = Database::getConnection()->select('media_field_data', 'mfd')
      ->fields('mfd', ['mid', 'name', 'bundle']);
    
    $results = $query->execute()->fetchAll();

    // Build table header.
    $header = [
      'mid' => $this->t('Media ID'),
      'name' => $this->t('Name'),
      'type' => $this->t('Type'),
      'send' => $this->t('Send to Remote'),
    ];

    // Build table rows.
    $rows = [];
    foreach ($results as $row) {
      $rows[] = [
        'mid' => $row->mid,
        'name' => $row->name,
        'type' => $row->bundle,
        'send' => Link::fromTextAndUrl($this->t('Send'), Url::fromRoute('admin_content_dashboard.send_media', ['mid' => $row->mid])),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No media entities available.'),
    ];
  }

  /**
   * Add media entity to the sync queue.
   */
  public function sendToRemote($mid) {
    // Load the media entity.
    $media = Media::load($mid);

    if (!$media) {
      \Drupal::messenger()->addError($this->t('Media not found.'));
      return $this->redirect('admin_content_dashboard.media_list');
    }

    // Load config and remote sites.
    $config = $this->configFactory->get('config_sync.settings');
    $remote_sites = $config->get('remote_sites') ?? [];

    // Loop through remote sites and add to queue.
    foreach ($remote_sites as $site) {
      Database::getConnection()->insert('synchronisation_queue')
        ->fields([
          'nid' => $media->id(),
          'title' => $media->label(),
          'remote_site' => $site['site_name'],
          'operation' => 'create',
          'status' => 'awaiting',
          'created' => time(),
          'entity_type' => 'media',
        ])
        ->execute();

      \Drupal::messenger()->addMessage($this->t('Media added to sync queue for @site.', ['@site' => $site['site_name']]));
    }

    return $this->redirect('admin_content_dashboard.media_list');
  }

  public function pushMedia($qid) {
    // Load the queue item from the database.
    $queue_item = Database::getConnection()->select('synchronisation_queue', 'q')
    ->fields('q', ['qid', 'nid', 'remote_site', 'operation', 'language'])
    ->condition('qid', $qid)
    ->execute()
    ->fetchObject();

    if (!$queue_item) {
      \Drupal::messenger()->addError($this->t('Queue item not found.'));
      return $this->redirect('admin_content_dashboard.queue'); // Redirect to the queue page
    }

    $mid = $queue_item->nid;
    // Load the media entity for the given media ID (mid).
    $media = \Drupal\media\Entity\Media::load($mid);
  
    if (!$media) {
      \Drupal::messenger()->addError($this->t('Media entity not found.'));
      return $this->redirect('admin_content_dashboard.media_list');
    }
  
    // Prepare media data based on its type.
    $bundle = $media->bundle();
    $media_data = [];
    switch ($bundle) {
      case 'image':
        $field_name = 'field_media_image';
        break;
      case 'audio':
        $field_name = 'field_media_audio_file';
        break;
      case 'video':
        $field_name = 'field_media_video_file';
        break;
      case 'remote_video':
        $field_name = 'field_media_oembed_video';  // Remote video URL.
        break;
      case 'document':
        $field_name = 'field_media_document';
        break;
      default:
        \Drupal::messenger()->addError($this->t('Unsupported media type.'));
        return $this->redirect('admin_content_dashboard.queue');
    }
  
    // Handle remote video differently.
    if ($bundle === 'remote_video') {
      $media_data = [
        'type' => 'remote_video',
        'url' => $media->get($field_name)->value,
      ];
    } else {
      // For media types that have files, fetch the file information.
      $fid = $media->get($field_name)->target_id;
      $file = \Drupal\file\Entity\File::load($fid);
  
      if (!$file) {
        \Drupal::messenger()->addError($this->t('File not found for this media.'));
        return $this->redirect('admin_content_dashboard.media_list');
      }
  
      $file_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      $filename = $file->getFilename();
      
      $media_data = [
        'type' => $bundle,
        'url' => $file_url,
        'filename' => $filename,
      ];
    }

    // Prepare the data to send.
    $data = [
      'media' => $media_data,
    ];
  
    // Get remote site details from configuration.
    $config = \Drupal::config('config_sync.settings');
    $remote_sites = $config->get('remote_sites');
  
    foreach ($remote_sites as $site) {

      if ($site['site_name'] == $queue_item->remote_site) {
        $username = $site['username'];
        $password = $site['password'];

        // Logging the media data being sent.
        \Drupal::logger('media_sync')->debug('<pre>@data</pre>', ['@data' => print_r($data, TRUE)]);
    
        // Send the media file to the remote site via POST request.
        $response = $this->sendPostRequest($site['url'], $data, $username, $password, $mid, $site['site_name']);
    
        // Check the response and update the queue status accordingly.
        if ($response['success']) {
          \Drupal::messenger()->addMessage($this->t('Media successfully pushed to @site.', ['@site' => $site['site_name']]));
    
          // Store the mapping between local and remote media IDs.
          Database::getConnection()->insert('relation_of_synchronisation')
            ->fields([
              'nid' => $mid,
              'title' => $media->label(),
              'remote_nid' => $response['remote_mid'],
              'remote_site' => $site['site_name'],
              'operation_date' => time(),
              'content_type' => 'media',
            ])
            ->execute();
        } else {
          \Drupal::messenger()->addError($this->t('Failed to push media to @site. Check logs for details.', ['@site' => $site['site_name']]));
        }
      }
    }
  
    return $this->redirect('admin_content_dashboard.queue');
  }
  

  /**
   * Send media to remote site via POST request.
   */
  public function sendPostRequest($url, $data, $username, $password, $mid, $remote_site_name) {
    $client = \Drupal::httpClient();
    $media = Media::load($mid);

    if (!$media) {
      return ['success' => false, 'message' => 'Media not found.'];
    }

    // Determine media type and get the associated file or URL.
    $bundle = $media->bundle();
    switch ($bundle) {
      case 'image':
        $field_name = 'field_media_image';
        break;
      case 'audio':
        $field_name = 'field_media_audio_file';
        break;
      case 'video':
        $field_name = 'field_media_video_file';
        break;
      case 'remote_video':
        $field_name = 'field_media_oembed_video';  // Remote video URL.
        break;
      case 'document':
        $field_name = 'field_media_document';
        break;
      default:
        return ['success' => false, 'message' => 'Unsupported media type.'];
    }

    // Prepare data for the media type.
    if ($bundle === 'remote_video') {
      // For remote video, send the URL.
      $media_data = [
        'type' => 'remote_video',
        'url' => $media->get($field_name)->value,
      ];
    } else {

      $fid = $media->get($field_name)->target_id;
      $file = \Drupal\file\Entity\File::load($fid);
      $file_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      $filename = $file->getFilename();
      // // For other media types, handle file upload.
      // $file_field = $media->get($field_name);
      // if ($file_field->isEmpty()) {
      //   return ['success' => false, 'message' => 'File field is empty.'];
      // }

      // $file = $file_field->entity;
      // if (!$file instanceof \Drupal\file\Entity\File) {
      //   return ['success' => false, 'message' => 'No file associated with this media.'];
      // }

      // $file_uri = $file->getFileUri();
      // if (file_exists($file_uri)) {
      //   $file_contents = file_get_contents($file_uri);
      //   $filename = $file->getFilename();
      // } else {
      //   return ['success' => false, 'message' => 'File does not exist.'];
      // }
      $media_data = [
        'type' => $bundle,
          'url' => $file_url,
          'filename' => $filename,
      ];
    }
    \Drupal::logger('module_name')->notice('<pre>'.print_r($media_data, TRUE).'</pre>');
    // Send POST request to remote site.
    try {
      $response = $client->post($url . '/api/media-sync', [
        'body' => json_encode($media_data),
        'auth' => [$username, $password],
        'verify' => false,
        'headers' => [
          'Content-Type' => 'application/json',
        ],
      ]);

      $statusCode = $response->getStatusCode();
      \Drupal::logger('module_name')->notice('<pre>'.print_r($statusCode, TRUE).'</pre>');
      if ($statusCode === 200) {
        $responseData = json_decode($response->getBody()->getContents(), true);


        if (!empty($responseData['media_id'])) {
          $remote_mid = $responseData['media_id'];

          \Drupal::logger('module_name')->notice('<pre>'.print_r($remote_mid, TRUE).'</pre>');

          // Store the mapping between local and remote media IDs.
          Database::getConnection()->insert('relation_of_synchronisation')
            ->fields([
              'nid' => $mid,
              'title' => $media->label(),
              'remote_nid' => $remote_mid,
              'remote_site' => $remote_site_name,
              'operation_date' => time(),
              'content_type' => 'media',
            ])
            ->execute();

          return ['success' => true, 'remote_mid' => $remote_mid];
        }

        return ['success' => false, 'message' => 'No Remote MID returned from the remote site.'];
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