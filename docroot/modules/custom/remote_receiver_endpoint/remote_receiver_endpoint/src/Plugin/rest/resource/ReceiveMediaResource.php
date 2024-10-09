<?php

namespace Drupal\remote_receiver_endpoint\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\media\Entity\Media;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Drupal\user\Entity\User;

/**
 * Receives media data from the parent site and creates a new media entity.
 *
 * @RestResource(
 *   id = "receive_media_resource",
 *   label = @Translation("Receive Media Resource"),
 *   uri_paths = {
 *     "create" = "/api/receive-media"
 *   }
 * )
 */
class ReceiveMediaResource extends ResourceBase {

   /**
    * Handles POST requests to create media from incoming media data.
    */
    public function post($data) {
      // Check if media data is available
      // if (empty($data['media']) || empty($data['media']['media_type']) || empty($data['media'])) {
      //   throw new HttpException(400, 'Media data, type, and file are required.');
      // }

      // Retrieve media-specific data
      $media_type = $data['media']['media_type'];
      // $file_data = $data['media']['file'];

      // Validate file data (for simplicity, assuming file ID is provided)
      // if (empty($file_data['fid'])) {
      //   throw new HttpException(400, 'File ID (fid) is required.');
      // }

      // Retrieve the author details, fallback to a default user if not provided
      $author_uid = !empty($data['media']['author']['uid']) ? $data['media']['author']['uid'] : 1;
      $author = User::load($author_uid);
      if (!$author) {
        $author = User::load(1); // Default to user ID 1 if the author is not found
      }

      // Create a new media entity with the incoming data
      $media_entity = Media::create([
        'bundle' => $media_type,  // Media type, e.g., 'image', 'document', etc.
        'name' => $data['media']['name'] ?? 'Unnamed media',
        'uid' => $author->id(),
        'status' => 1,  // Set the status to 'published'
        // 'field_media_image' => [  // Adjust this field based on media type
        //   'target_id' => $file_data['fid'],  // Use the file ID provided
        //   'alt' => $file_data['alt'] ?? '',
        //   'title' => $file_data['title'] ?? '',
        // ],
      ]);

      try {
        $media_entity->save();
        // Return the media entity's ID
        return new JsonResponse(['message' => 'Media created successfully.', 'mid' => $media_entity->id()]);
      } catch (\Exception $e) {
        \Drupal::logger('media_receiver')->error($e->getMessage());
        throw new HttpException(500, 'Failed to create media.');
      }
    }
}
