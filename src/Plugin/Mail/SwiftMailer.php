<?php

namespace Drupal\swiftmailer\Plugin\Mail;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Random;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Asset\AssetResolverInterface;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Site\Settings;
use Drupal\swiftmailer\TransportFactoryInterface;
use Drupal\swiftmailer\Utility\Conversion;
use Exception;
use Html2Text\Html2Text;
use Psr\Log\LoggerInterface;
use stdClass;
use Swift_Attachment;
use Swift_Image;
use Swift_Mailer;
use Swift_Message;
use Symfony\Component\DependencyInjection\ContainerInterface;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\mailsystem\MailsystemManager;

/**
 * Provides a 'Swift Mailer' plugin to send emails.
 *
 * @Mail(
 *   id = "swiftmailer",
 *   label = @Translation("Swift Mailer"),
 *   description = @Translation("Swift Mailer Plugin.")
 * )
 */
class SwiftMailer implements MailInterface, ContainerFactoryPluginInterface {

  /**
   * An array containing configuration settings.
   *
   * @var array
   */
  protected $config;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The transport factory service.
   *
   * @var \Drupal\swiftmailer\TransportFactoryInterface
   */
  protected $transportFactory;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The theme manager.
   *
   * @var \Drupal\Core\Theme\ThemeManagerInterface
   */
  protected $themeManager;

  /**
   * The asset resolver.
   *
   * @var \Drupal\Core\Asset\AssetResolverInterface
   */
  protected $assetResolver;

  /**
   * SwiftMailer constructor.
   *
   * @param \Drupal\swiftmailer\TransportFactoryInterface $transport_factory
   *   The transport factory service.
   * @param \Drupal\Core\Config\ImmutableConfig $message
   *   The swiftmailer message configuration.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\Core\Theme\ThemeManagerInterface $theme_manager
   *   The theme manager.
   * @param \Drupal\Core\Asset\AssetResolverInterface $asset_resolver
   *   The asset resolver.
   */
  public function __construct(TransportFactoryInterface $transport_factory, ImmutableConfig $message, LoggerInterface $logger, RendererInterface $renderer, ModuleHandlerInterface $module_handler, MailManagerInterface $mail_manager, ThemeManagerInterface $theme_manager, AssetResolverInterface $asset_resolver) {
    $this->transportFactory = $transport_factory;
    $this->config['message'] = $message->get();
    $this->logger = $logger;
    $this->renderer = $renderer;
    $this->moduleHandler = $module_handler;
    $this->mailManager = $mail_manager;
    $this->themeManager = $theme_manager;
    $this->assetResolver = $asset_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('swiftmailer.transport'),
      $container->get('config.factory')->get('swiftmailer.message'),
      $container->get('logger.factory')->get('swiftmailer'),
      $container->get('renderer'),
      $container->get('module_handler'),
      $container->get('plugin.manager.mail'),
      $container->get('theme.manager'),
      $container->get('asset.resolver')
    );
  }

  /**
   * Formats a message composed by drupal_mail().
   *
   * @param array $message
   *   A message array holding all relevant details for the message.
   *
   * @return array
   *   The message as it should be sent.
   */
  public function format(array $message) {
    $message = $this->massageMessageBody($message);

    // Get applicable format.
    $applicable_format = $this->getApplicableFormat($message);

    // Theme message if format is set to be HTML.
    if ($applicable_format == SWIFTMAILER_FORMAT_HTML) {
      // Attempt to use the mail theme defined in MailSystem.
      if ($this->mailManager instanceof MailsystemManager) {
        $mail_theme = $this->mailManager->getMailTheme();
      }
      // Default to the active theme if MailsystemManager isn't used.
      else {
        $mail_theme = $this->themeManager->getActiveTheme()->getName();
      }
      $render = [
        '#theme' => isset($message['params']['theme']) ? $message['params']['theme'] : 'swiftmailer',
        '#message' => $message,
        '#attached' => [
          'library' => ["$mail_theme/swiftmailer"],
        ],
      ];

      $message['body'] = $this->renderer->renderPlain($render);

      if (empty($message['plain']) && $this->config['message']['convert_mode'] || !empty($message['params']['convert'])) {
        $converter = new Html2Text($message['body']);
        $message['plain'] = $converter->getText();
      }

      // Process CSS from libraries.
      $assets = AttachedAssets::createFromRenderArray($render);
      $css = '';
      // Request optimization so that the CssOptimizer performs essential
      // processing such as @include.
      foreach ($this->assetResolver->getCssAssets($assets, TRUE) as $css_asset) {
        $css .= file_get_contents($css_asset['data']);
      }

      if ($css) {
        $message['body'] = (new CssToInlineStyles())->convert($message['body'], $css);
      }
    }

    // We replace all 'image:foo' in the body with a unique magic string like
    // 'cid:[randomname]' and keep track of this. It will be replaced by the
    // final "cid" in ::embed().
    $random = new Random();
    $embeddable_images = [];
    $processed_images = [];
    preg_match_all('/"image:([^"]+)"/', $message['body'], $embeddable_images);
    for ($i = 0; $i < count($embeddable_images[0]); $i++) {
      $image_id = $embeddable_images[0][$i];
      if (isset($processed_images[$image_id])) {
        continue;
      }
      $image_path = trim($embeddable_images[1][$i]);
      $image_name = basename($image_path);

      if (mb_substr($image_path, 0, 1) == '/') {
        $image_path = mb_substr($image_path, 1);
      }

      $image = new \stdClass();
      $image->uri = $image_path;
      $image->filename = $image_name;
      $image->filemime = \Drupal::service('file.mime_type.guesser')->guess($image_path);
      $image->cid = $random->name(8, TRUE);
      $message['params']['images'][] = $image;
      $message['body'] = preg_replace($image_id, 'cid:' . $image->cid, $message['body']);
      $processed_images[$image_id] = 1;
    }

    return $message;
  }

  /**
   * Sends a message composed by drupal_mail().
   *
   * @param array $message
   *   A message array holding all relevant details for the message.
   *
   * @return bool
   *   TRUE if the message was successfully sent, and otherwise FALSE.
   */
  public function mail(array $message) {
    try {

      // Create a new message.
      $m = new Swift_Message($message['subject']);

      // Not all Drupal headers should be added to the e-mail message.
      // Some headers must be suppressed in order for Swift Mailer to
      // do its work properly.
      $suppressable_headers = swiftmailer_get_supressable_headers();

      // Keep track of whether we need to respect the provided e-mail
      // format or not.
      $respect_format = $this->config['message']['respect_format'];

      // Process headers provided by Drupal. We want to add all headers which
      // are provided by Drupal to be added to the message. For each header we
      // first have to find out what type of header it is, and then add it to
      // the message as the particular header type.
      if (!empty($message['headers']) && is_array($message['headers'])) {
        foreach ($message['headers'] as $header_key => $header_value) {

          // Check wether the current header key is empty or represents
          // a header that should be suppressed. If yes, then skip header.
          if (empty($header_key) || in_array($header_key, $suppressable_headers)) {
            continue;
          }

          // Skip 'Content-Type' header if the message to be sent will be a
          // multipart message or the provided format is not to be respected.
          if ($header_key == 'Content-Type' && (!$respect_format || swiftmailer_is_multipart($message))) {
            continue;
          }

          // Get header type.
          $header_type = Conversion::swiftmailer_get_headertype($header_key, $header_value);

          // Add the current header to the e-mail message.
          switch ($header_type) {
            case SWIFTMAILER_HEADER_ID:
              Conversion::swiftmailer_add_id_header($m, $header_key, $header_value);
              break;

            case SWIFTMAILER_HEADER_PATH:
              Conversion::swiftmailer_add_path_header($m, $header_key, $header_value);
              break;

            case SWIFTMAILER_HEADER_MAILBOX:
              Conversion::swiftmailer_add_mailbox_header($m, $header_key, $header_value);
              break;

            case SWIFTMAILER_HEADER_DATE:
              Conversion::swiftmailer_add_date_header($m, $header_key, $header_value);
              break;

            case SWIFTMAILER_HEADER_PARAMETERIZED:
              Conversion::swiftmailer_add_parameterized_header($m, $header_key, $header_value);
              break;

            default:
              Conversion::swiftmailer_add_text_header($m, $header_key, $header_value);
              break;

          }
        }
      }

      // \Drupal\Core\Mail\Plugin\Mail\PhpMail respects $message['to'] but for
      // 'from' and 'reply-to' it uses the headers (which are set in
      // MailManager::doMail). Replicate that behavior here.
      Conversion::swiftmailer_add_mailbox_header($m, 'To', $message['to']);

      // Get applicable format.
      $applicable_format = $this->getApplicableFormat($message);

      // Get applicable character set.
      $applicable_charset = $this->getApplicableCharset($message);

      // Set body.
      $m->setBody($message['body'], $applicable_format, $applicable_charset);

      // Add alternative plain text version if format is HTML and plain text
      // version is available.
      if ($applicable_format == SWIFTMAILER_FORMAT_HTML && !empty($message['plain'])) {
        $m->addPart($message['plain'], SWIFTMAILER_FORMAT_PLAIN, $applicable_charset);
      }

      // Validate that $message['params']['files'] is an array.
      if (empty($message['params']['files']) || !is_array($message['params']['files'])) {
        $message['params']['files'] = [];
      }

      // Let other modules get the chance to add attachable files.
      $files = $this->moduleHandler->invokeAll('swiftmailer_attach', ['key' => $message['key'], 'message' => $message]);
      if (!empty($files) && is_array($files)) {
        $message['params']['files'] = array_merge(array_values($message['params']['files']), array_values($files));
      }

      // Attach files.
      if (!empty($message['params']['files']) && is_array($message['params']['files'])) {
        $this->attach($m, $message['params']['files']);
      }

      // Attach files (provide compatibility with mimemail)
      if (!empty($message['params']['attachments']) && is_array($message['params']['attachments'])) {
        $this->attachAsMimeMail($m, $message['params']['attachments']);
      }

      // Embed images.
      if (!empty($message['params']['images']) && is_array($message['params']['images'])) {
        $this->embed($m, $message['params']['images']);
      }

      // Get the configured transport type.
      $transport_type = $this->transportFactory->getDefaultTransportMethod();
      $transport = $this->transportFactory->getTransport($transport_type);

      /** @var \Swift_Mailer $mailer */
      $mailer = new Swift_Mailer($transport);

      // Allows other modules to customize the message.
      $this->moduleHandler->alter('swiftmailer', $mailer, $m, $message);

      // Send the message.
      Conversion::swiftmailer_filter_message($m);
      return (bool) $mailer->send($m);
    }
    catch (Exception $e) {
      $headers = !empty($m) ? $m->getHeaders() : '';
      $headers = !empty($headers) ? nl2br($headers->toString()) : 'No headers were found.';
      $this->logger->error(
        'An attempt to send an e-mail message failed, and the following error
        message was returned : @exception_message<br /><br />The e-mail carried
        the following headers:<br /><br />@headers',
        ['@exception_message' => $e->getMessage(), '@headers' => $headers]);
    }
    return FALSE;
  }

  /**
   * Process attachments.
   *
   * @param \Swift_Message $m
   *   The message which attachments are to be added to.
   * @param array $files
   *   The files which are to be added as attachments to the provided message.
   *
   * @internal
   */
  protected function attach(Swift_Message $m, array $files) {

    // Iterate through each array element.
    foreach ($files as $file) {

      if ($file instanceof stdClass) {

        // Validate required fields.
        if (empty($file->uri) || empty($file->filename) || empty($file->filemime)) {
          continue;
        }

        // Get file data.
        if (UrlHelper::isValid($file->uri, TRUE)) {
          $content = file_get_contents($file->uri);
        }
        else {
          $content = file_get_contents(\Drupal::service('file_system')->realpath($file->uri));
        }

        $filename = $file->filename;
        $filemime = $file->filemime;

        // Attach file.
        $m->attach(new Swift_Attachment($content, $filename, $filemime));
      }
    }

  }

  /**
   * Process MimeMail attachments.
   *
   * @param \Swift_Message $m
   *   The message which attachments are to be added to.
   * @param array $attachments
   *   The attachments which are to be added message.
   *
   * @internal
   */
  protected function attachAsMimeMail(Swift_Message $m, array $attachments) {
    // Iterate through each array element.
    foreach ($attachments as $a) {
      if (is_array($a)) {
        // Validate that we've got either 'filepath' or 'filecontent.
        if (empty($a['filepath']) && empty($a['filecontent'])) {
          continue;
        }

        // Validate required fields.
        if (empty($a['filename']) || empty($a['filemime'])) {
          continue;
        }

        // Attach file (either using a static file or provided content).
        if (!empty($a['filepath'])) {
          $file = new stdClass();
          $file->uri = $a['filepath'];
          $file->filename = $a['filename'];
          $file->filemime = $a['filemime'];
          $this->attach($m, [$file]);
        }
        else {
          $m->attach(new Swift_Attachment($a['filecontent'], $a['filename'], $a['filemime']));
        }
      }
    }
  }

  /**
   * Process inline images..
   *
   * @param \Swift_Message $m
   *   The message which inline images are to be added to.
   * @param array $images
   *   The images which are to be added as inline images to the provided
   *   message.
   *
   * @internal
   */
  protected function embed(Swift_Message $m, array $images) {

    // Iterate through each array element.
    foreach ($images as $image) {

      if ($image instanceof stdClass) {

        // Validate required fields.
        if (empty($image->uri) || empty($image->filename) || empty($image->filemime) || empty($image->cid)) {
          continue;
        }

        // Keep track of the 'cid' assigned to the embedded image.
        $cid = NULL;

        // Get image data.
        if (UrlHelper::isValid($image->uri, TRUE)) {
          $content = file_get_contents($image->uri);
        }
        else {
          $content = file_get_contents(\Drupal::service('file_system')->realpath($image->uri));
        }

        $filename = $image->filename;
        $filemime = $image->filemime;

        // Embed image.
        $cid = $m->embed(new Swift_Image($content, $filename, $filemime));

        // The provided 'cid' needs to be replaced with the 'cid' returned
        // by the Swift Mailer library.
        $body = $m->getBody();
        $body = preg_replace('/cid:' . $image->cid . '/', $cid, $body);
        $m->setBody($body);
      }
    }
  }

  /**
   * Returns the applicable format.
   *
   * @param array $message
   *   The message for which the applicable format is to be determined.
   *
   * @return string
   *   A string being the applicable format.
   *
   * @internal
   */
  protected function getApplicableFormat(array $message) {
    // Get the configured default format.
    $default_format = $this->config['message']['format'];

    // Get whether the provided format is to be respected.
    $respect_format = $this->config['message']['respect_format'];

    // Check if a format has been provided particularly for this message. If
    // that is the case, then apply that format instead of the default format.
    $applicable_format = !empty($message['params']['format']) ? $message['params']['format'] : $default_format;

    // Check if the provided format is to be respected, and if a format has been
    // set through the header "Content-Type". If that is the case, the apply the
    // format provided. This will override any format which may have been set
    // through $message['params']['format'].
    if ($respect_format && !empty($message['headers']['Content-Type'])) {
      $format = $message['headers']['Content-Type'];

      if (preg_match('/.*\;/U', $format, $matches)) {
        $applicable_format = trim(substr($matches[0], 0, -1));
      }
      else {
        $applicable_format = $message['headers']['Content-Type'];
      }

    }

    return $applicable_format;

  }

  /**
   * Returns the applicable charset.
   *
   * @param array $message
   *   The message for which the applicable charset is to be determined.
   *
   * @return string
   *   A string being the applicable charset.
   *
   * @internal
   */
  protected function getApplicableCharset(array $message) {

    // Get the configured default format.
    $default_charset = $this->config['message']['character_set'];

    // Get whether the provided format is to be respected.
    $respect_charset = $this->config['message']['respect_format'];

    // Check if a format has been provided particularly for this message. If
    // that is the case, then apply that format instead of the default format.
    $applicable_charset = !empty($message['params']['charset']) ? $message['params']['charset'] : $default_charset;

    // Check if the provided format is to be respected, and if a format has been
    // set through the header "Content-Type". If that is the case, the apply the
    // format provided. This will override any format which may have been set
    // through $message['params']['format'].
    if ($respect_charset && !empty($message['headers']['Content-Type'])) {
      $format = $message['headers']['Content-Type'];
      $format = preg_match('/charset.*=.*\;/U', $format, $matches);

      if ($format > 0) {
        $applicable_charset = trim(substr($matches[0], 0, -1));
        $applicable_charset = preg_replace('/charset=/', '', $applicable_charset);
      }
      else {
        $applicable_charset = $default_charset;
      }

    }

    return $applicable_charset;

  }

  /**
   * Massages the message body into the format expected for rendering.
   *
   * @param array $message
   *   The message.
   *
   * @return array
   *   The render array for message body.
   *
   * @internal
   */
  protected function massageMessageBody(array $message) {
    $applicable_format = $this->getApplicableFormat($message);
    $filter_format = $this->config['message']['filter_format'];

    foreach ($message['body'] as &$body) {
      $is_markup = ($body instanceof MarkupInterface);

      if (!$is_markup && ($applicable_format == SWIFTMAILER_FORMAT_HTML)) {
        // Convert to HTML.  The default 'plain_text' format escapes markup,
        // converts new lines to <br> and converts URLs to links.
        $build = [
          '#type' => 'processed_text',
          '#text' => $body,
          '#format' => $filter_format,
        ];
        $body = $this->renderer->renderPlain($build);
      }

      if ($is_markup && ($applicable_format == SWIFTMAILER_FORMAT_PLAIN)) {
        // Convert to plain text.
        $body = MailFormatHelper::htmlToText($body);
      }
    }

    // Merge all lines in the e-mail body separated by the mail line endings.
    // Treat the result as safe markup even for plain text format to prevent
    // Twig auto-escape.
    $line_endings = Settings::get('mail_line_endings', PHP_EOL);
    $message['body'] = Markup::create(implode($line_endings, $message['body']));

    return $message;
  }

}
