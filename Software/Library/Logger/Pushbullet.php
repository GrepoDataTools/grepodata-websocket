<?php

namespace Grepodata\Library\Logger;

class Pushbullet
{
  /**
   * Sends alert to developer. This function is not async, use with caution!
   * @param $message
   * @param string $title
   * @return bool|string
   */
  public static function SendPushMessage($message, $title = 'gd websocket notification')
  {
    if (!isset($message) || $message == '') {
      return false;
    }

    // Build request
    $aParams = array(
      'body' => $message,
      'title' => $title,
      'type' => 'note',
    );
    $data = json_encode($aParams);

    // Do curl
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, 'https://api.pushbullet.com/v2/pushes');
    curl_setopt($curl, CURLOPT_USERPWD, PRIVATE_PUSHBULLET_TOKEN);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'Content-Length: ' . strlen($data)
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, false);

    // Execute
    $response = curl_exec($curl);
    curl_close($curl);

    return $response;
  }

}
