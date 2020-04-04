<?php

namespace Drupal\Tests\swiftmailer\Kernel\Plugin\Mail;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Render\Markup;
use Drupal\KernelTests\KernelTestBase;

/**
 * @coversDefaultClass \Drupal\swiftmailer\Plugin\Mail\SwiftMailer
 * @group swiftmailer
 */
class SwiftMailerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'filter',
    'swiftmailer',
  ];

  /**
   * The swiftmailer plugin.
   *
   * @var \Drupal\swiftmailer\Plugin\Mail\SwiftMailer
   */
  protected $plugin;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->installConfig([
      'swiftmailer',
      'filter',
    ]);
    $this->installEntitySchema('user');
    $this->installSchema('user', 'users_data');
    $this->plugin = $this->container->get('plugin.manager.mail')
      ->createInstance('swiftmailer');
  }

  /**
   * Tests massaging the message body.
   *
   * @dataProvider bodyDataProvider
   */
  public function testMassageMessageBody(array $message, $expected, $expected_plain = NULL) {
    $message['params']['format'] = SWIFTMAILER_FORMAT_HTML;
    $actual = $this->plugin->massageMessageBody($message);
    $this->assertSame(is_array($expected) ? implode(PHP_EOL, $expected) : $expected, (string) $actual['body']);

    if ($expected_plain) {
      $message['params']['format'] = SWIFTMAILER_FORMAT_PLAIN;
      $actual = $this->plugin->massageMessageBody($message);
      $this->assertSame(is_array($expected_plain) ? implode(PHP_EOL, $expected_plain) : $expected_plain, (string) $actual['body']);
    }
  }

  /**
   * Data provider of body data.
   */
  public function bodyDataProvider() {
    return [
      'with html' => [
        'message' => [
          'body' => [
            Markup::create('<p>Lorem ipsum dolor sit amet</p>'),
            Markup::create('<p>consetetur sadipscing elitr</p>'),
            Markup::create('<p>sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat</p>'),
            Markup::create('<p>sed diam voluptua.</p>'),
          ],
        ],
        'expected' => "<p>Lorem ipsum dolor sit amet</p>\n<p>consetetur sadipscing elitr</p>\n<p>sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat</p>\n<p>sed diam voluptua.</p>",
      ],
      'no html' => [
        'message' => [
          'body' => [
            "Lorem ipsum dolor sit amet\nconsetetur sadipscing elitr\nsed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat\nsed diam voluptua.",
          ],
        ],
        'expected' => "<p>Lorem ipsum dolor sit amet<br />\nconsetetur sadipscing elitr<br />\nsed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat<br />\nsed diam voluptua.</p>\n",
      ],
      'mixed' => [
        'message' => [
          'body' => [
            'Hello World',
            'Hello <strong>World</strong>',
            new FormattableMarkup('Hello World #@number', ['@number' => 2]),
            Markup::create('Hello <strong>World</strong>'),
          ],
        ],
        // Output is wrong due to https://www.drupal.org/project/swiftmailer/issues/3122389.
        'expected' => ["<p>Hello World</p>\n", "Hello *World*\n", "<p>Hello World #2</p>\n", "Hello <strong>World</strong>"],
        'expected_plain' => ["Hello World\n", "Hello *World*\n", "Hello World #2", "Hello <strong>World</strong>"],
      ],
    ];
  }

}
