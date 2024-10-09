<?php

namespace Drupal\remote_receiver_endpoint\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Drupal\user\Entity\User;

/**
 * Receives content from the parent site and creates a new node.
 *
 * @RestResource(
 *   id = "receive_content_resource",
 *   label = @Translation("Receive Content Resource"),
 *   uri_paths = {
 *     "create" = "/api/receive-content"
 *   }
 * )
 */
class ReceiveContentResource extends ResourceBase {

   /**
    * Handles POST requests to create a node from incoming content.
    */
    public function post($data) {
      // Check if required content is available
      if (empty($data['content']) || empty($data['content']['title'])) {
        throw new HttpException(400, 'Content data is required.');
      }
    
      // Retrieve the body if it exists
      $body = !empty($data['content']['body']) ? $data['content']['body'] : '';
    
      // Retrieve the language code from the incoming data
      $langcode = !empty($data['content']['language']) ? $data['content']['language'] : 'en';
    
      // Retrieve the author details, fallback to a default user if not provided
      $author_uid = !empty($data['content']['author']['uid']) ? $data['content']['author']['uid'] : 1;
      $author = User::load($author_uid);
      if (!$author) {
        $author = User::load(1); // Default to user ID 1 if the author is not found
      }
    
      // Load the existing node using the nid
      $existing_node = Node::load($data['content']['nid']);
      
      if ($existing_node) {
        // Check if the node already has a translation for the given language
        if ($existing_node->hasTranslation($langcode)) {
          // Load the translation and update its fields
          $translated_node = $existing_node->getTranslation($langcode);
          $translated_node->set('title', $data['content']['title']);
          $translated_node->set('body', [
            'value' => $body,
            'format' => 'full_html',
          ]);
        } else {
          // If no translation exists, add one
          $translated_node = $existing_node->addTranslation($langcode, [
            'title' => $data['content']['title'],
            'body' => [
              'value' => $body,
              'format' => 'full_html',
            ],
          ]);
        }
    
        // Save the translation
        try {
          $translated_node->save();
          return new JsonResponse([
            'message' => 'Translation updated successfully on the child site.',
            'nid' => $existing_node->id(),
          ], 200);
        } catch (\Exception $e) {
          \Drupal::logger('child_content_receiver')->error($e->getMessage());
          return new JsonResponse([
            'message' => 'Failed to update translation: ' . $e->getMessage(),
          ], 500);
        }
      } else {
        // Create a new node with the incoming content and language
        $node = Node::create([
          'type' => $data['content']['content_type'],
          'title' => $data['content']['title'],
          'uid' => $author->id(),
          'status' => 1,  // Set the status to 'published'
          'body' => [
            'value' => $body,
            'format' => 'full_html',
          ],
          'langcode' => $langcode,  // Set the language for the node
        ]);
    
        try {
          $node->save();
          return new JsonResponse([
            'message' => 'Content created successfully on the child site.',
            'nid' => $node->id(),
          ], 200);
        } catch (\Exception $e) {
          \Drupal::logger('child_content_receiver')->error($e->getMessage());
          return new JsonResponse([
            'message' => 'Failed to create content: ' . $e->getMessage(),
          ], 500);
        }
      }
    }
    
    
    
}