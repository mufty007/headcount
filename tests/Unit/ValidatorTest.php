<?php

namespace Headcount\Tests\Unit;

use Headcount\Tests\TestCase;
use Headcount\Helpers\Validator;

/**
 * Validator Tests
 * Tests input validation functions
 */
class ValidatorTest extends TestCase
{
    public function testEmailValidation()
    {
        // Valid emails
        $this->assertTrue(Validator::email('test@example.com'));
        $this->assertTrue(Validator::email('user.name@domain.co.uk'));
        
        // Invalid emails
        $this->assertFalse(Validator::email('invalid'));
        $this->assertFalse(Validator::email('invalid@'));
        $this->assertFalse(Validator::email('@example.com'));
        $this->assertFalse(Validator::email(''));
    }

    public function testDisposableEmailDetection()
    {
        $this->assertTrue(Validator::isDisposableEmail('user@mailinator.com'));
        $this->assertTrue(Validator::isDisposableEmail('x@yopmail.com'));
        $this->assertTrue(Validator::isDisposableEmail('a@temp-mail.org'));
        $this->assertFalse(Validator::isDisposableEmail('person@gmail.com'));
        $this->assertFalse(Validator::isDisposableEmail('admin@example.com'));
    }

    public function testEmailDomainHelper()
    {
        $this->assertSame('example.com', Validator::emailDomain('User@Example.com'));
        $this->assertNull(Validator::emailDomain('not-an-email'));
    }

    public function testPhoneValidation()
    {
        // Valid phones
        $this->assertTrue(Validator::phone('1234567890'));
        $this->assertTrue(Validator::phone('(123) 456-7890'));
        $this->assertTrue(Validator::phone('123-456-7890'));
        
        // Invalid phones
        $this->assertFalse(Validator::phone('123'));
        $this->assertFalse(Validator::phone(''));
    }

    public function testDateValidation()
    {
        // Valid dates
        $this->assertTrue(Validator::date('2024-12-31'));
        $this->assertTrue(Validator::date('2024-01-01'));
        
        // Invalid dates
        $this->assertFalse(Validator::date('invalid'));
        $this->assertFalse(Validator::date('2024-13-01')); // Invalid month
        $this->assertFalse(Validator::date(''));
    }

    public function testRequiredValidation()
    {
        $this->assertTrue(Validator::required('value'));
        $this->assertTrue(Validator::required('0')); // String zero is not empty
        
        // Empty values
        $this->assertFalse(Validator::required(''));
        $this->assertFalse(Validator::required(null));
        
        // Arrays
        $this->assertTrue(Validator::required([1, 2, 3]));
        $this->assertFalse(Validator::required([]));
    }
}
