<?php

namespace Drupal\remote_receiver_endpoint\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;

/**
 * Provides a resource to create media entities from an external API.
 *
 * @RestResource(
 *   id = "media_sync_resource",
 *   label = @Translation("Media Sync Resource"),
 *   uri_paths = {
 *     "canonical" = "/api/media-sync",
 *     "create" = "/api/media-sync"
 *   }
 * )
 */
class MediaSyncResource extends ResourceBase {

  /**
   * Responds to POST requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The response containing the created media entity ID or error message.
   */
  public function post(Request $request) {
    // Parse the incoming JSON data.
    $data = json_decode($request->getContent(), true);

    if (empty($data['type']) || empty($data['file'])) {
      return new ResourceResponse(['error' => 'Missing media type or file data'], 400);
    }

    try {
      // Determine the media type and handle the file data.
      $media_type = $data['type'];
      $file_data = $data['file'];
      $file_name = $file_data['filename'];
      $file_contents = base64_decode($file_data['contents']);
      
      // Save the file using Drupal's static service access.
      $file = $this->saveFile($file_contents, $file_name);
      
      if ($media_type === 'remote_video') {
        // Handle remote video media (no file involved, only URL).
        $media = $this->createRemoteVideoMedia($data['url'], $file_name);
      } 
      else {
        // Handle all other media types.
        $media = $this->createMediaEntity($media_type, $file, $file_name);
      }

      // Return a success response with the media entity ID.
      return new ResourceResponse(['message' => 'Media created', 'media_id' => $media->id()], 200);
    }
    catch (\Exception $e) {
      // Log any errors and return a failure response.
      \Drupal::logger('remote_receiver_endpoint')->error($e->getMessage());
      return new ResourceResponse(['error' => 'Error processing media: ' . $e->getMessage()], 500);
    }
  }

  /**
   * Save the file to the public file system.
   *
   * @param string $file_contents
   *   The base64-decoded contents of the file.
   * @param string $file_name
   *   The name of the file.
   *
   * @return \Drupal\file\Entity\File
   *   The created file entity.
   */
  protected function saveFile($file_contents, $file_name) {
    $directory = 'public://media_sync';
    
    // Use the file system service to save the file.
    \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $file_path = \Drupal::service('file_system')->saveData($file_contents, "$directory/$file_name", FileSystemInterface::EXISTS_REPLACE);

    // Create and save the File entity.
    $file = File::create([
      'uri' => $file_path,
      'status' => 1,
    ]);
    $file->save();

    return $file;
  }

  /**
   * Create a media entity based on the provided media type.
   *
   * @param string $media_type
   *   The type of media to create (e.g., image, document, video).
   * @param \Drupal\file\Entity\File $file
   *   The file entity associated with the media.
   * @param string $file_name
   *   The name of the file.
   *
   * @return \Drupal\media\Entity\Media
   *   The created media entity.
   *
   * @throws \Exception
   *   Thrown when an unsupported media type is provided.
   */
  protected function createMediaEntity($media_type, $file, $file_name) {
    // Handle different media types and map them to appropriate fields.
    switch ($media_type) {
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
        throw new \Exception('Unsupported media type.');
    }

    // Create the Media entity.
    $media = Media::create([
      'bundle' => $media_type,
      'name' => $file_name,
      'uid' => 1, // Adjust for your use case.
      'status' => 1,
      $field_name => [
        'target_id' => $file->id(),
        'alt' => $file_name, // Optionally, add alt text for images.
      ],
    ]);

    // Save the media entity and return it.
    $media->save();

    return $media;
  }

  /**
   * Handle remote video media creation (e.g., YouTube/Vimeo).
   *
   * @param string $video_url
   *   The URL of the remote video.
   * @param string $name
   *   The name of the video.
   *
   * @return \Drupal\media\Entity\Media
   *   The created remote video media entity.
   */
  protected function createRemoteVideoMedia($video_url, $name) {
    // Assume the remote video field is 'field_media_oembed_video'.
    $media = Media::create([
      'bundle' => 'remote_video',
      'name' => $name,
      'uid' => 1, // Adjust for your use case.
      'status' => 1,
      'field_media_oembed_video' => [
        'value' => $video_url,
      ],
    ]);

    // Save the media entity and return it.
    $media->save();

    return $media;
  }
}
