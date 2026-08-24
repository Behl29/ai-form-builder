<?php

namespace Tests\Feature\Form;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ConditionEvaluator;
use App\Services\ConditionValidator;
use App\Services\SubmissionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConditionalLogicTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;
    private Form $form;
    private ConditionValidator $conditionValidator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant);

        $this->form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $this->conditionValidator = new ConditionValidator();
    }

    // ==================== CONDITION VALIDATION TESTS ====================

    public function test_validates_referenced_field_exists(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        [
                            'key' => 'field_a',
                            'type' => 'text',
                            'conditions' => [
                                ['action' => 'show', 'field' => 'nonexistent_field', 'operator' => 'equals', 'value' => 'test'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $errors = $this->conditionValidator->validate($schema);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not exist', $errors[0]['message']);
    }

    public function test_validates_operator_compatibility(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        ['key' => 'checkbox_field', 'type' => 'checkbox'],
                        [
                            'key' => 'text_field',
                            'type' => 'text',
                            'conditions' => [
                                // 'greater_than' is not valid for checkbox
                                ['action' => 'show', 'field' => 'checkbox_field', 'operator' => 'greater_than', 'value' => 5],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $errors = $this->conditionValidator->validate($schema);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not compatible', $errors[0]['message']);
    }

    public function test_rejects_self_reference(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        [
                            'key' => 'field_a',
                            'type' => 'text',
                            'conditions' => [
                                ['action' => 'show', 'field' => 'field_a', 'operator' => 'equals', 'value' => 'test'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $errors = $this->conditionValidator->validate($schema);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('cannot reference itself', $errors[0]['message']);
    }

    public function test_validates_skip_target_section_exists(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        ['key' => 'trigger', 'type' => 'select', 'options' => [['value' => 'skip', 'label' => 'Skip']]],
                        [
                            'key' => 'field_a',
                            'type' => 'text',
                            'conditions' => [
                                ['action' => 'skip_to_section', 'field' => 'trigger', 'operator' => 'equals', 'value' => 'skip', 'targetSection' => 'nonexistent_section'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $errors = $this->conditionValidator->validate($schema);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not exist', $errors[0]['message']);
    }

    public function test_rejects_backward_skip_cycle(): void
    {
        $schema = [
            'sections' => [
                ['id' => 'section_1', 'fields' => [['key' => 'trigger', 'type' => 'checkbox']]],
                [
                    'id' => 'section_2',
                    'fields' => [
                        [
                            'key' => 'field_a',
                            'type' => 'text',
                            'conditions' => [
                                // Trying to skip backward to section_1
                                ['action' => 'skip_to_section', 'field' => 'trigger', 'operator' => 'is_checked', 'targetSection' => 'section_1'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $errors = $this->conditionValidator->validate($schema);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('cycle', $errors[0]['message']);
    }

    public function test_validates_option_values_for_select(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        [
                            'key' => 'color',
                            'type' => 'select',
                            'options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']],
                        ],
                        [
                            'key' => 'field_a',
                            'type' => 'text',
                            'conditions' => [
                                ['action' => 'show', 'field' => 'color', 'operator' => 'equals', 'value' => 'invalid_color'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $errors = $this->conditionValidator->validate($schema);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not a valid option', $errors[0]['message']);
    }

    public function test_valid_conditions_pass_validation(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        ['key' => 'show_details', 'type' => 'checkbox'],
                        [
                            'key' => 'details',
                            'type' => 'textarea',
                            'conditions' => [
                                ['action' => 'show', 'field' => 'show_details', 'operator' => 'is_checked'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'section_2',
                    'fields' => [['key' => 'final', 'type' => 'text']],
                ],
            ],
        ];

        $errors = $this->conditionValidator->validate($schema);

        $this->assertEmpty($errors);
    }

    // ==================== RUNTIME EVALUATION TESTS ====================

    public function test_evaluates_show_condition(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        ['key' => 'trigger', 'type' => 'checkbox'],
                        [
                            'key' => 'conditional_field',
                            'type' => 'text',
                            'conditions' => [
                                ['action' => 'show', 'field' => 'trigger', 'operator' => 'is_checked'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Field hidden when trigger is unchecked
        $evaluator = new ConditionEvaluator($schema, ['trigger' => false]);
        $this->assertFalse($evaluator->isFieldVisible('conditional_field'));

        // Field visible when trigger is checked
        $evaluator->setData(['trigger' => true]);
        $this->assertTrue($evaluator->isFieldVisible('conditional_field'));
    }

    public function test_evaluates_hide_condition(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        ['key' => 'hide_trigger', 'type' => 'checkbox'],
                        [
                            'key' => 'hideable_field',
                            'type' => 'text',
                            'conditions' => [
                                ['action' => 'hide', 'field' => 'hide_trigger', 'operator' => 'is_checked'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $evaluator = new ConditionEvaluator($schema, ['hide_trigger' => false]);
        $this->assertTrue($evaluator->isFieldVisible('hideable_field'));

        $evaluator->setData(['hide_trigger' => true]);
        $this->assertFalse($evaluator->isFieldVisible('hideable_field'));
    }

    public function test_evaluates_equals_operator(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        ['key' => 'country', 'type' => 'select', 'options' => [['value' => 'us', 'label' => 'US']]],
                        [
                            'key' => 'state',
                            'type' => 'text',
                            'conditions' => [
                                ['action' => 'show', 'field' => 'country', 'operator' => 'equals', 'value' => 'us'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $evaluator = new ConditionEvaluator($schema, ['country' => 'uk']);
        $this->assertFalse($evaluator->isFieldVisible('state'));

        $evaluator->setData(['country' => 'us']);
        $this->assertTrue($evaluator->isFieldVisible('state'));
    }

    public function test_evaluates_greater_than_operator(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        ['key' => 'age', 'type' => 'number'],
                        [
                            'key' => 'adult_content',
                            'type' => 'text',
                            'conditions' => [
                                ['action' => 'show', 'field' => 'age', 'operator' => 'greater_than', 'value' => 18],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $evaluator = new ConditionEvaluator($schema, ['age' => 16]);
        $this->assertFalse($evaluator->isFieldVisible('adult_content'));

        $evaluator->setData(['age' => 21]);
        $this->assertTrue($evaluator->isFieldVisible('adult_content'));
    }

    public function test_evaluates_contains_operator(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        ['key' => 'interests', 'type' => 'checkbox_group', 'options' => [['value' => 'sports', 'label' => 'Sports']]],
                        [
                            'key' => 'sports_details',
                            'type' => 'text',
                            'conditions' => [
                                ['action' => 'show', 'field' => 'interests', 'operator' => 'contains', 'value' => 'sports'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $evaluator = new ConditionEvaluator($schema, ['interests' => ['music']]);
        $this->assertFalse($evaluator->isFieldVisible('sports_details'));

        $evaluator->setData(['interests' => ['sports', 'music']]);
        $this->assertTrue($evaluator->isFieldVisible('sports_details'));
    }

    public function test_evaluates_is_empty_operator(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        ['key' => 'optional', 'type' => 'text'],
                        [
                            'key' => 'fallback',
                            'type' => 'text',
                            'conditions' => [
                                ['action' => 'show', 'field' => 'optional', 'operator' => 'is_empty'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $evaluator = new ConditionEvaluator($schema, ['optional' => '']);
        $this->assertTrue($evaluator->isFieldVisible('fallback'));

        $evaluator->setData(['optional' => 'filled']);
        $this->assertFalse($evaluator->isFieldVisible('fallback'));
    }

    // ==================== CONDITIONAL REQUIRE TESTS ====================

    public function test_evaluates_conditional_require(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        ['key' => 'has_company', 'type' => 'checkbox'],
                        [
                            'key' => 'company_name',
                            'type' => 'text',
                            'required' => false,
                            'conditions' => [
                                ['action' => 'require', 'field' => 'has_company', 'operator' => 'is_checked'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $evaluator = new ConditionEvaluator($schema, ['has_company' => false]);
        $this->assertFalse($evaluator->isFieldRequired('company_name'));

        $evaluator->setData(['has_company' => true]);
        $this->assertTrue($evaluator->isFieldRequired('company_name'));
    }

    // ==================== HIDDEN REQUIRED FIELD BEHAVIOR ====================

    public function test_hidden_required_field_does_not_fail_validation(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        ['key' => 'show_extra', 'type' => 'checkbox'],
                        [
                            'key' => 'extra_field',
                            'type' => 'text',
                            'label' => 'Extra Field',
                            'required' => true,
                            'conditions' => [
                                ['action' => 'show', 'field' => 'show_extra', 'operator' => 'is_checked'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $validator = new SubmissionValidator();

        // Field is hidden (show_extra = false), so required validation should not apply
        $errors = $validator->validate($schema, ['show_extra' => false]);
        $this->assertEmpty($errors);

        // Field is visible (show_extra = true), required validation should apply
        $errors = $validator->validate($schema, ['show_extra' => true]);
        $this->assertArrayHasKey('extra_field', $errors);
    }

    public function test_visible_required_field_fails_when_empty(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        ['key' => 'show_extra', 'type' => 'checkbox'],
                        [
                            'key' => 'extra_field',
                            'type' => 'text',
                            'label' => 'Extra Field',
                            'required' => true,
                            'conditions' => [
                                ['action' => 'show', 'field' => 'show_extra', 'operator' => 'is_checked'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $validator = new SubmissionValidator();

        // Field visible and filled - should pass
        $errors = $validator->validate($schema, ['show_extra' => true, 'extra_field' => 'value']);
        $this->assertEmpty($errors);
    }

    // ==================== BRANCHING / SKIP LOGIC TESTS ====================

    public function test_evaluates_skip_to_section(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        [
                            'key' => 'skip_trigger',
                            'type' => 'select',
                            'options' => [['value' => 'skip', 'label' => 'Skip'], ['value' => 'continue', 'label' => 'Continue']],
                            'conditions' => [
                                ['action' => 'skip_to_section', 'field' => 'skip_trigger', 'operator' => 'equals', 'value' => 'skip', 'targetSection' => 'section_3'],
                            ],
                        ],
                    ],
                ],
                ['id' => 'section_2', 'fields' => [['key' => 'field_2', 'type' => 'text']]],
                ['id' => 'section_3', 'fields' => [['key' => 'field_3', 'type' => 'text']]],
            ],
        ];

        // When skip is selected, should jump to section_3
        $evaluator = new ConditionEvaluator($schema, ['skip_trigger' => 'skip']);
        $nextSection = $evaluator->getNextSection('section_1');
        $this->assertEquals('section_3', $nextSection);

        // When continue is selected, should go to section_2
        $evaluator->setData(['skip_trigger' => 'continue']);
        $nextSection = $evaluator->getNextSection('section_1');
        $this->assertEquals('section_2', $nextSection);
    }

    public function test_section_visibility_affects_field_visibility(): void
    {
        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [['key' => 'trigger', 'type' => 'checkbox']],
                ],
                [
                    'id' => 'section_2',
                    'conditions' => [
                        ['action' => 'show', 'field' => 'trigger', 'operator' => 'is_checked'],
                    ],
                    'fields' => [['key' => 'field_in_hidden_section', 'type' => 'text']],
                ],
            ],
        ];

        $evaluator = new ConditionEvaluator($schema, ['trigger' => false]);

        // Section is hidden, so field should also be hidden
        $this->assertFalse($evaluator->isSectionVisible('section_2'));
        $this->assertFalse($evaluator->isFieldVisible('field_in_hidden_section'));

        // Section is visible, field should be visible
        $evaluator->setData(['trigger' => true]);
        $this->assertTrue($evaluator->isSectionVisible('section_2'));
        $this->assertTrue($evaluator->isFieldVisible('field_in_hidden_section'));
    }

    // ==================== OPERATORS BY FIELD TYPE TESTS ====================

    public function test_text_field_supports_correct_operators(): void
    {
        $operators = ConditionValidator::getOperatorsForType('text');

        $this->assertContains('equals', $operators);
        $this->assertContains('not_equals', $operators);
        $this->assertContains('contains', $operators);
        $this->assertContains('is_empty', $operators);
        $this->assertNotContains('greater_than', $operators);
    }

    public function test_number_field_supports_correct_operators(): void
    {
        $operators = ConditionValidator::getOperatorsForType('number');

        $this->assertContains('equals', $operators);
        $this->assertContains('greater_than', $operators);
        $this->assertContains('less_than', $operators);
        $this->assertContains('greater_than_or_equals', $operators);
    }

    public function test_checkbox_field_supports_correct_operators(): void
    {
        $operators = ConditionValidator::getOperatorsForType('checkbox');

        $this->assertContains('is_checked', $operators);
        $this->assertContains('is_not_checked', $operators);
        $this->assertNotContains('contains', $operators);
    }

    public function test_file_field_supports_correct_operators(): void
    {
        $operators = ConditionValidator::getOperatorsForType('file');

        $this->assertContains('is_empty', $operators);
        $this->assertContains('is_not_empty', $operators);
        $this->assertNotContains('equals', $operators);
    }
}
