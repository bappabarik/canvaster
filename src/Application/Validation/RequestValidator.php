<?php
declare(strict_types=1);

namespace App\Application\Validation;

use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validatable;

class RequestValidator
{
    /**
     * @param array<string, mixed>       $data
     * @param array<string, Validatable> $rules
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(array $data, array $rules): array
    {
        $errors = [];
        $clean  = [];

        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            try {
                $rule->setName($field)->assert($value);
                $clean[$field] = $value;
            } catch (NestedValidationException $e) {
                $messages      = $e->getMessages();
                $errors[$field] = reset($messages) ?: "Invalid {$field}";
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $clean;
    }
}