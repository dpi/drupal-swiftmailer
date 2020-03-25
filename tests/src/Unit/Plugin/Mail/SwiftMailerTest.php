<?php

namespace Drupal\Tests\swiftmailer\Unit\Plugin\Mail;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Render\Markup;
use Drupal\swiftmailer\Plugin\Mail\SwiftMailer;
use PHPUnit\Framework\TestCase;

define('SWIFTMAILER_FORMAT_HTML', 'text/html');

/**
 * @coversDefaultClass \Drupal\swiftmailer\Plugin\Mail\SwiftMailer
 * @group swiftmailer
 */
class SwiftMailerTest extends TestCase {

  /**
   * @covers ::massageMessageBody
   */
  public function testMassageMessageBody() {
    $method = new \ReflectionMethod(SwiftMailer::class, 'massageMessageBody');
    $method->setAccessible(TRUE);

    $mailer = $this->getMockBuilder(SwiftMailer::class)
      ->disableOriginalConstructor()
      ->getMock();

    $body = [
      'Hello World',
      'Hello <strong>World</strong>',
      new FormattableMarkup('Hello World #@number', ['@number' => 2]),
      Markup::create('Hello <strong>World</strong>'),
    ];

    $result = [
      'Hello World',
      "\nHello *World*\n",
      'Hello World #2',
      'Hello <strong>World</strong>',
    ];

    $message = $method->invoke($mailer, ['body' => $body]);
    $this->assertSame(implode(PHP_EOL, $result), (string) $message['body']);
  }

}
