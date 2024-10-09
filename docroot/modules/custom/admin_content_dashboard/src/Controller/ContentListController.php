<?php

namespace Drupal\admin_content_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use GuzzleHttp\Client;
use Drupal\Core\Url;
use Drupal\Core\Link;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class ContentListController.
 *
 * @package Drupal\admin_content_dashboard\Controller
 */
class ContentListController extends ControllerBase {

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
   * Determines whether to push content or media based on entity_type.
   *
   * @param int $qid
   *   The queue ID from the synchronisation_queue.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the appropriate push handler.
   */
  public function determinePushType($qid) {
    // Fetch the queue item based on the qid.
    $queue_item = Database::getConnection()->select('synchronisation_queue', 'q')
      ->fields('q', ['qid', 'entity_type'])
      ->condition('qid', $qid)
      ->execute()
      ->fetchObject();

      if (!$queue_item) {
        \Drupal::messenger()->addError($this->t('Queue item not found.'));
        return new RedirectResponse(Url::fromRoute('admin_content_dashboard.queue')->toString());
      }

    // Determine the appropriate action based on entity_type.
    switch ($queue_item->entity_type) {
      case 'content':
        // Redirect to the pushContent route.
        return new RedirectResponse(Url::fromRoute('admin_content_dashboard.content_push', ['qid' => $qid])->toString());

      case 'media':
        // Redirect to the pushMedia route.
        return new RedirectResponse(Url::fromRoute('admin_content_dashboard.media_push', ['qid' => $qid])->toString());
      
      case 'menu':
        // Redirect to the pushMenu route.
        return new RedirectResponse(Url::fromRoute('admin_content_dashboard.menu_push', ['qid' => $qid])->toString());

      case 'taxonomy_term':
        // Redirect to the pushMenu route.
        return new RedirectResponse(Url::fromRoute('admin_content_dashboard.taxonomy_push', ['qid' => $qid])->toString());

      default:
        \Drupal::messenger()->addError($this->t('Unsupported entity type.'));
        return new RedirectResponse(Url::fromRoute('admin_content_dashboard.queue')->toString());
    }
  }

  // Display a table with content.  
  public function contentList() {
    // Query to get the content along with the content type
    $query = Database::getConnection()->select('node_field_data', 'n')
      ->fields('n', ['nid', 'title', 'uid', 'created', 'type', 'langcode'])  // Added 'type' to select content type
      ->condition('status', 1);

    // Execute the query and fetch results
    $results = $query->execute()->fetchAll();

    // Load config settings from custom module.
    $config = $this->configFactory->get('config_sync.settings');

    // Get remote sites configuration from config.
    $remote_sites = $config->get('remote_sites') ?? [];

    // Build the table header
    $header = [
      'nid' => $this->t('Entity ID'),
      'title' => $this->t('Title'),
      'language' => $this->t('Language'),  // Added Language column.
      'author' => $this->t('Author'),
      'content_type' => $this->t('Content Type'),  // Added Content Type column
      'created' => $this->t('Date'),
      'send' => $this->t('Schedule for Content Sync'),
    ];

    $rows = [];
    foreach ($results as $row) {

      $author = \Drupal\user\Entity\User::load($row->uid);
      
      // Get the human-readable content type label
      $content_type = \Drupal\node\Entity\NodeType::load($row->type)->label();
    
      // Always list the content
      $rows[] = [
        'nid' => $row->nid,
        'title' => $row->title,
        'language' => $row->langcode,  // Display the language of the node.
        'author' => $author->getDisplayName(),
        'content_type' => $content_type,
        'created' => date('Y-m-d H:i:s', $row->created),
        'send' => Link::fromTextAndUrl($this->t('Schedule for Content Sync'), Url::fromRoute('admin_content_dashboard.send', [
          'nid' => $row->nid,
          'langcode' => $row->langcode,  // Pass the langcode as well.
        ])),

        // For multilingual 
        // 'send' => Link::fromTextAndUrl($this->t('Schedule for Content Sync'), Url::fromRoute('admin_content_dashboard.send', [
        //   'nid' => $translated_node->id(), // Pass the translated node ID.
        //   'langcode' => $current_langcode   // Pass the correct language code.
        // ])),
      ];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['content-list-container']],  // Add a custom class for styling
      'heading' => [
          '#markup' => '<h2>' . $this->t('Content List') . '</h2>',
          '#prefix' => '<div class="content-list-heading">',  // Add custom classes for styling
          '#suffix' => '</div>',
      ],
      // Add the dropdown for sorting.
     
      'table' => [
          '#type' => 'table',
          '#header' => $header,
          '#rows' => $rows,
          '#empty' => $this->t('No content available.'),
      ],
    ];

    return $build;
  }

  public function sendToRemote($nid, $langcode = null) {
    // Load the node
    $node = \Drupal\node\Entity\Node::load($nid);
    
    if (!$node) {
      \Drupal::messenger()->addError($this->t('Content not found.'));
      return $this->redirect('admin_content_dashboard.content_list');
    }

    // If a langcode is provided, load the translation.
      if ($langcode && $node->hasTranslation($langcode)) {
        $node = $node->getTranslation($langcode);
      } else {
        $langcode = $node->language()->getId();  // Default to node's language if no langcode is passed.
      }

    $config = $this->configFactory->get('config_sync.settings');
    $remote_sites = $config->get('remote_sites') ?? [];

    foreach ($remote_sites as $site) {
      if (in_array($node->bundle(), $site['content_types'])) {
        // Assign default null values if they are not provided
        // $entity_type = $node->bundle() ?? null;
        $entity_type = 'content';

        // Insert into the queue instead of sending directly
        Database::getConnection()->insert('synchronisation_queue')
          ->fields([
            'nid' => $node->id(),
            'title' => $node->getTitle(),
            'remote_site' => $site['site_name'],
            'operation' => 'create',  // assuming create for now
            'status' => 'awaiting',
            'language' => $langcode, // Fetch the current language dynamically
            'entity_type' => $entity_type,  // Add entity_type  
            'created' => time(),
          ])
          ->execute();
        
          \Drupal::messenger()->addMessage($this->t('Content added to queue for @site in @language.', [
            '@site' => $site['site_name'],
            '@language' => \Drupal::languageManager()->getLanguageName($langcode),
          ]));
      }
    }

    return $this->redirect('admin_content_dashboard.content_list');
  }

  public function sendPostRequest($url, $data, $username, $password, $nid, $remote_site_name) {
    $client = \Drupal::httpClient();
    
    // Load the node object using the nid from the data array.
    $node = \Drupal\node\Entity\Node::load($data['content']['nid']);
    
    // Check if the node exists.
    if (!$node) {
      return ['success' => false, 'message' => 'Node not found.'];
    }

    // Get the language from the data instead of the node.
    $langcode = $data['language'] ?? $node->language()->getId();  // Use langcode from data, or fallback to node language.

    // Check if the node has a translation for the given language.
    if ($node->hasTranslation($langcode)) {
      // Load the translated node for the specified language.
      $node = $node->getTranslation($langcode);
    }

    // Get the field value manually.
      $paragraph_data = [];
      if ($node->hasField('field_paragraphs')) {  // Ensure 'field_paragraphs' is the correct field name.
        $paragraph_items = $node->get('field_paragraphs')->getValue();  // Use getValue() to get raw data.
        
        foreach ($paragraph_items as $paragraph_item) {
          if (!empty($paragraph_item['target_id'])) {
            // Load the paragraph entity using the target_id.
            $paragraph = \Drupal\paragraphs\Entity\Paragraph::load($paragraph_item['target_id']);
            if ($paragraph) {
              // Serialize the paragraph, including child and grandchild paragraphs.
              $paragraph_data[] = $this->serializeParagraph($paragraph);  // Serialize the paragraph.
            }
          }
        }
      }


    // Check if content already exists in relation_of_synchronisation table
    $existing_entry = Database::getConnection()->select('relation_of_synchronisation', 'ros')
      ->fields('ros', ['nid'])
      ->condition('nid', $nid)
      ->condition('remote_site', $remote_site_name)
      ->condition('language', $langcode)
      ->execute()
      ->fetchField();

    if ($existing_entry) {
      // Log the message that the content already exists in queue_logs
      Database::getConnection()->insert('queue_logs')
        ->fields([
          'qid' => $nid,  // Log using entity_id (nid)
          'log_message' => 'Error: Content already exists on the remote site ' . $remote_site_name,
          'created' => time(),
        ])
        ->execute();

      // Return a response indicating the content already exists
      return ['success' => false, 'message' => 'Content Already Exists on ' . $remote_site_name];
    }

    // Get the content type dynamically
    $content_type = $node->getType();

    // Assign default null values if fields are not set
    // $entity_type = $node->bundle() ?? null;
    $entity_type = 'content';

    // Prepare data payload to send to the remote site.
    $data = array_merge($data, [
      'entity_type' => $node->bundle(),  // Add entity_type dynamically.
      'paragraphs' => $paragraph_data,   // Add paragraph data
    ]);

    try {
      \Drupal::logger('send_post_request')->debug('<pre>@data</pre>', ['@data' => print_r($data, TRUE)]);

      $response = $client->post($url . '/api/receive-content', [
        'body' => json_encode($data),
        'auth' => [$username, $password],
        'verify' => false,
        'headers' => [
          'Content-Type' => 'application/json',
        ],
      ]);

      $statusCode = $response->getStatusCode();
      $responseData = json_decode($response->getBody()->getContents(), true);

      if ($statusCode === 200) {
        $remote_nid = $responseData['nid'] ?? null;
        if ($remote_nid) {
          // Log the relation of synchronization
          Database::getConnection()->insert('relation_of_synchronisation')
            ->fields([
              'nid' => $nid,
              'title' => $node->getTitle(),
              'remote_nid' => $remote_nid,
              'content_type' => $content_type,
              'remote_site' => $remote_site_name,
              'entity_type' => $entity_type,
              'language' => $langcode,
              'operation_date' => time(),
            ])
            ->execute();

          // Log success in queue_logs
          Database::getConnection()->insert('queue_logs')
            ->fields([
              'qid' => $nid, // Assuming qid is the nid in this case
              'log_message' => 'Success: Content created on remote site. Message: ' . $responseData['message'],
              'created' => time(),
            ])
            ->execute();

          return ['success' => true, 'remote_nid' => $remote_nid];
        } else {
          // Log missing nid in response
          Database::getConnection()->insert('queue_logs')
            ->fields([
              'qid' => $nid,
              'log_message' => 'Error: No Remote NID returned from the child site.',
              'created' => time(),
            ])
            ->execute();

          return ['success' => false, 'message' => 'No Remote NID returned from the child site.'];
        }
      } else {
        // Handle unexpected status codes (not 200)
        Database::getConnection()->insert('queue_logs')
          ->fields([
            'qid' => $nid,
            'log_message' => 'Error: HTTP ' . $statusCode . ' - ' . $responseData['message'] ?? 'Unexpected error.',
            'created' => time(),
          ])
          ->execute();

        return ['success' => false, 'message' => 'Unexpected error occurred (HTTP ' . $statusCode . ').'];
      }
    } catch (RequestException $e) {
      // Log request exception details in queue_logs
      Database::getConnection()->insert('queue_logs')
        ->fields([
          'qid' => $nid,
          'log_message' => 'Error: RequestException - ' . $e->getMessage(),
          'created' => time(),
        ])
        ->execute();

      return ['success' => false, 'message' => 'Failed to send data: ' . $e->getMessage()];
    }
  }

public function pushContent($qid) {
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

  // Load the node for the given nid.
  $node = \Drupal\node\Entity\Node::load($queue_item->nid);

  \Drupal::logger('push_content')->debug('Attempting to load translation for language: @langcode', ['@langcode' => $queue_item->language]);


  // Check if the node has a translation in the desired language.
  if ($node->hasTranslation($queue_item->language)) {
    $node = $node->getTranslation($queue_item->language);
    \Drupal::logger('push_content')->debug('Loaded translated node: @title', ['@title' => $node->getTitle()]);
  } else {
      \Drupal::logger('push_content')->error('Translation not found for language: @langcode', ['@langcode' => $queue_item->language]);
  }


  if (!$node) {
      \Drupal::messenger()->addError($this->t('Content not found.'));
      return $this->redirect('admin_content_dashboard.queue'); // Redirect to the queue page
  }

  // Prepare the data to be sent to the remote site.
  $data = [
      'content' => [
          'title' => $node->getTitle(),
          'body' => $node->get('body')->value,
          'nid' => $node->id(),
          'content_type' => $node->getType(),
          'language' => $queue_item->language,
      ],
  ];

  // Check if the content type has 'field_basic_media'.
  if ($node->hasField('field_basic_media') && !$node->get('field_basic_media')->isEmpty()) {
    // Get the media entity referenced by 'field_basic_media'.
    $media_reference = $node->get('field_basic_media')->target_id;

    if ($media_reference) {
      // Load the media entity.
      $media = \Drupal\media\Entity\Media::load($media_reference);

      // Prepare media data for inclusion in the content payload.
      if ($media) {
        $media_data = [];

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
          case 'document':
            $field_name = 'field_media_document';
            break;
          default:
            $field_name = null;
        }

        if ($field_name && $media->hasField($field_name)) {
          // Assuming the media has a file associated with it.
          $fid = $media->get($field_name)->target_id;
          $file = \Drupal\file\Entity\File::load($fid);
          $file_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
          $filename = $file->getFilename();

          // Add media information to the media_data array.
          $media_data = [
            'type' => $bundle,
            'url' => $file_url,
            'filename' => $filename,
          ];
        }

        // Add the media data to the content payload.
        if (!empty($media_data)) {
          $data['content']['media'] = $media_data;
        }
      }
    }
  }

  // Get remote site details.
  $config = \Drupal::config('config_sync.settings');
  $remote_sites = $config->get('remote_sites');

  foreach ($remote_sites as $site) {
      if ($site['site_name'] == $queue_item->remote_site) {
          $username = $site['username'];
          $password = $site['password'];

          // Logging the data being sent
          \Drupal::logger('push_content')->debug('<pre>@data</pre>', ['@data' => print_r($data, TRUE)]);

          // Send content immediately.
          $response = $this->sendPostRequest($site['url'], $data, $username, $password, $node->id(), $queue_item->remote_site);

          // Check response and update the queue status accordingly.
          if ($response['success']) {
              // Optionally, you can remove the queue item if it's no longer needed.
              \Drupal::database()->delete('synchronisation_queue')
                  ->condition('qid', $queue_item->qid)
                  ->execute();
                  
              \Drupal::messenger()->addMessage($this->t('Content successfully pushed to @site.', ['@site' => $queue_item->remote_site]));
          } else {
              \Drupal::messenger()->addError($this->t('Failed to push content. View more in logs.'));
          }
      }
  }

  return $this->redirect('admin_content_dashboard.queue'); // Redirect back to the queue page.
}

public function serializeParagraph($paragraph) {
  $data = [
    'id' => $paragraph->id(),
    'type' => $paragraph->bundle(),
    'fields' => [],
  ];

  // Loop through paragraph fields and serialize them.
  foreach ($paragraph->getFields() as $field_name => $field) {
    if ($field->getFieldDefinition()->isTranslatable() || $field->getFieldDefinition()->isDisplayConfigurable('form')) {
      $data['fields'][$field_name] = $field->value;
    }
  }

  // Handle child paragraphs.
  if ($paragraph->hasField('field_child_paragraph')) {
    $child_paragraphs = $paragraph->get('field_child_paragraph')->referencedEntities();
    foreach ($child_paragraphs as $child_paragraph) {
      // Serialize child paragraphs and handle nested grandchild paragraphs within them.
      $child_data = $this->serializeParagraph($child_paragraph);

      // Handle grandchild paragraphs if they exist.
      if ($child_paragraph->hasField('field_grand_child_paragraph')) {
        $grand_child_paragraphs = $child_paragraph->get('field_grand_child_paragraph')->referencedEntities();
        foreach ($grand_child_paragraphs as $grand_child_paragraph) {
          // Serialize and add the grandchild paragraphs.
          $child_data['fields']['field_grand_child_paragraph'][] = $this->serializeParagraph($grand_child_paragraph);
        }
      }

      // Add serialized child data with nested grandchild paragraphs (if any) to the parent.
      $data['fields']['field_child_paragraph'][] = $child_data;
    }
  }

  return $data;
}



  public function queue() {
    $header = [
      'operation' => $this->t('Operation'),
      'title' => $this->t('Title'),
      'content_type' => $this->t('Content Type'),
      'entity_type' => $this->t('Entity Type'),  // Added new column
      'language' => $this->t('Language'),  // Added new column
      'nid' => $this->t('Entity ID'),
      'remote_site' => $this->t('Remote Site Name'),
      'created' => $this->t('Created'),
      'status' => $this->t('Status'),
      'action' => $this->t('Action'),
    ];
  
    // Fetch data from the queue table
    $query = Database::getConnection()->select('synchronisation_queue', 'q')
      ->fields('q', ['qid', 'operation', 'nid', 'title', 'remote_site', 'created', 'status', 'entity_type', 'language']);  // Added entity_type and language
    $results = $query->execute()->fetchAll();
  
    $rows = [];
    foreach ($results as $row) {

      $langcode = $row->language;  // Default to row language if node load fails.

      $node = \Drupal\node\Entity\Node::load($row->nid);
      $content_type = $node ? $node->getType() : $row->entity_type;

      $rows[] = [
        'operation' => $row->operation,
        'title' => $row->title,
        'content_type' => $content_type,
        'entity_type' => $row->entity_type,  // Display entity_type
        'language' => \Drupal::languageManager()->getLanguageName($langcode),  // Fetch the language name.
        'nid' => $row->nid,
        'remote_site' => $row->remote_site,
        'created' => date('Y-m-d H:i:s', $row->created),
        // Modify the status field to include status + logs link
        'status' => [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '{{ status }} | {{ logs_link }}',
            '#context' => [
              'status' => $row->status,  // Display the actual status like 'Failed' or 'Processed'
              'logs_link' => Link::fromTextAndUrl($this->t('Logs'), Url::fromRoute('admin_content_dashboard.queue_logs', ['qid' => $row->nid]))->toRenderable(),  // Add logs link
            ],
          ],
        ],
        'action' => [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '{{ push_link }} | {{ edit_link }} | {{ remove_link }}',
            '#context' => [
              'push_link' => Link::fromTextAndUrl($this->t('Push'), Url::fromRoute('custom_module.determine_push_type', ['qid' => $row->qid]))->toRenderable(),
              // Change this to redirect to the node edit page.
              'edit_link' => Link::fromTextAndUrl($this->t('Edit'), Url::fromRoute('entity.node.edit_form', ['node' => $row->nid]))->toRenderable(),
              'remove_link' => Link::fromTextAndUrl($this->t('Remove'), Url::fromRoute('admin_content_dashboard.queue_remove', ['qid' => $row->qid]))->toRenderable(),
            ],
          ],
        ],
      ];
    }
  
    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No items in the queue.'),
    ];
  }
  

  public function queueLogs($qid) {
    // Fetch the logs from your custom queue_logs table.

    $connection = Database::getConnection();
    $query = $connection->select('queue_logs', 'ql')
      ->fields('ql', ['id', 'log_message', 'created'])
      ->condition('qid', $qid, '=');
    $logs = $query->execute()->fetchAll();
    
    // If there are no logs, return a message.
    if (empty($logs)) {
      return [
        '#markup' => $this->t('No logs available for this queue item.'),
      ];
    }

    // Build the header for the logs table.
    $header = [
      'id' => $this->t('Log ID'),
      'log_message' => $this->t('Log Message'),
      'created' => $this->t('Created'),
    ];

    // Build the rows for the table.
    $rows = [];
    foreach ($logs as $log) {
      $rows[] = [
        'id' => $log->id,
        'log_message' => $log->log_message,
        'created' => date('Y-m-d H:i:s', $log->created),
      ];
    }

    // Return the render array for the logs table.
    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No logs found.'),
    ];
  }


  public function removeQueueItem($qid) {
    Database::getConnection()->delete('synchronisation_queue')
      ->condition('qid', $qid)
      ->execute();
    
    \Drupal::messenger()->addMessage($this->t('Queue item removed successfully.'));
    return $this->redirect('admin_content_dashboard.queue');
  }
  

  public function relationOfSynchronisation() {
    $header = [
      'nid' => $this->t('Entity ID'),
      'title' => $this->t('Title'),  // Add title header
      'remote_nid' => $this->t('Remote Entity ID'),
      'content_type' => $this->t('Content Type'),
      'entity_type' => $this->t('Entity Type'),  // Added new column
      'language' => $this->t('Language'),  // Added new column
      'remote_site' => $this->t('Remote Site Name'),
      'operation_date' => $this->t('Source Operation Date'),
      'action' => $this->t('Action'),
    ];
  
    // Fetch data from the 'relation_of_synchronisation' table
    $query = Database::getConnection()->select('relation_of_synchronisation', 'ros')
      ->fields('ros', ['nid', 'title', 'remote_nid', 'content_type', 'entity_type', 'language', 'remote_site', 'operation_date']);  // Added entity_type and language
    $results = $query->execute()->fetchAll();
  
    $rows = [];
    foreach ($results as $row) {
      // Load the node to get the language.
      $node = \Drupal\node\Entity\Node::load($row->nid);
      $langcode = $node ? $node->language()->getId() : $row->language;

      $rows[] = [
        'nid' => $row->nid,
        'title' => $row->title,  // Display the title
        'remote_nid' => $row->remote_nid,
        'content_type' => $row->content_type,
        'entity_type' => $row->entity_type,  // Display entity_type
        'language' => \Drupal::languageManager()->getLanguageName($langcode),  // Fetch the language name.
        'remote_site' => $row->remote_site,
        'operation_date' => date('Y-m-d H:i:s', $row->operation_date),
        'action' => [
          'data' => [
            // Convert the URL to a renderable array using Link::fromTextAndUrl
            '#type' => 'inline_template',
            '#template' => '{{ edit_link }}',
            '#context' => [
              'edit_link' => Link::fromTextAndUrl($this->t('Edit Entity'), Url::fromRoute('entity.node.edit_form', ['node' => $row->nid]))->toRenderable(),
            ],
          ],
        ],
      ];
    }
  
    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No synchronization relations found.'),
    ];
  }
  
}