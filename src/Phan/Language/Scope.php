<?php
declare(strict_types=1);
namespace Phan\Language;

use AssertionError;
use Phan\Config;
use Phan\Language\Element\Variable;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\FQSEN\FullyQualifiedPropertyName;
use Phan\Language\Type\TemplateType;

/**
 * Represents the scope of a Context.
 *
 * This includes the current element which it is found in,
 * variables (etc.) found in that scope,
 * as well as any functionality to use/modify this information.
 *
 * A scope is either the global scope or a child scope of another scope.
 */
abstract class Scope
{
    /**
     * @var Scope|null the parent scope, if this is not the global scope
     */
    protected $parent_scope = null;

    /**
     * @var FQSEN|null the FQSEN that this scope is within,
     * if this scope is within an element such as a function body or class definition.
     */
    protected $fqsen = null;

    /**
     * @var array<string,Variable> the map of variable names to variables within this scope.
     * Some variable definitions must be retrieved from parent scopes.
     */
    protected $variable_map = [];

    /**
     * @var array<string,TemplateType>
     * A map from template type identifiers to the
     * TemplateType that parameterizes the generic class
     * in this scope.
     */
    private $template_type_map = [];

    /**
     * @param ?Scope $parent_scope
     * @param ?FQSEN $fqsen
     */
    public function __construct(
        Scope $parent_scope = null,
        FQSEN $fqsen = null
    ) {
        $this->parent_scope = $parent_scope;
        $this->fqsen = $fqsen;
    }

    /**
     * @return bool
     * True if this scope has a parent scope
     */
    public function hasParentScope() : bool
    {
        return $this->parent_scope !== null;
    }

    /**
     * @return Scope
     * Get the parent scope of this scope
     * @suppress PhanPossiblyNullTypeReturn callers should call hasParentScope
     */
    public function getParentScope() : Scope
    {
        return $this->parent_scope;
    }

    /**
     * @return bool
     * True if this scope has an FQSEN
     * @suppress PhanUnreferencedPublicMethod
     */
    public function hasFQSEN() : bool
    {
        return $this->fqsen !== null;
    }

    /**
     * @return FQSEN in which this scope was declared
     * (e.g. a FullyQualifiedFunctionName, FullyQualifiedClassName, etc.)
     * @suppress PhanPossiblyNullTypeReturn callers should call hasFQSEN
     */
    public function getFQSEN()
    {
        return $this->fqsen;
    }

    /**
     * @return bool
     * True if we're in a class scope
     */
    public function isInClassScope() : bool
    {
        return $this->hasParentScope()
            ? $this->getParentScope()->isInClassScope() : false;
    }

    /**
     * @return FullyQualifiedClassName
     * Crawl the scope hierarchy to get a class FQSEN.
     */
    public function getClassFQSEN() : FullyQualifiedClassName
    {
        if (!$this->hasParentScope()) {
            throw new AssertionError("Cannot get class FQSEN on scope");
        }

        return $this->getParentScope()->getClassFQSEN();
    }

    /**
     * @return ?FullyQualifiedClassName
     * Crawl the scope hierarchy to get a class FQSEN.
     */
    public function getClassFQSENOrNull()
    {
        if (!$this->hasParentScope()) {
            return null;
        }

        return $this->getParentScope()->getClassFQSENOrNull();
    }

    /**
     * @return bool
     * True if we're in a property scope
     */
    public function isInPropertyScope() : bool
    {
        return $this->hasParentScope()
            ? $this->getParentScope()->isInPropertyScope() : false;
    }

    /**
     * @return FullyQualifiedPropertyName
     * Crawl the scope hierarchy to get a class FQSEN.
     */
    public function getPropertyFQSEN() : FullyQualifiedPropertyName
    {
        if (!$this->hasParentScope()) {
            throw new AssertionError("Cannot get class FQSEN on scope");
        }

        return $this->getParentScope()->getPropertyFQSEN();
    }

    /**
     * @return bool
     * True if we're in a method/function/closure scope
     */
    public function isInFunctionLikeScope() : bool
    {
        return $this->hasParentScope()
            ? $this->getParentScope()->isInFunctionLikeScope() : false;
    }

    /**
     * @return FullyQualifiedMethodName|FullyQualifiedFunctionName
     * Crawl the scope hierarchy to get a method FQSEN.
     */
    public function getFunctionLikeFQSEN()
    {
        if (!$this->hasParentScope()) {
            throw new AssertionError("Cannot get method/function/closure FQSEN on scope");
        }

        return $this->getParentScope()->getFunctionLikeFQSEN();
    }

    /**
     * @return bool
     * True if a variable with the given name is defined
     * within this scope
     */
    public function hasVariableWithName(string $name) : bool
    {
        return \array_key_exists($name, $this->variable_map);
    }

    /**
     * Locates the variable with name $name.
     * Callers should check $this->hasVariableWithName() first.
     */
    public function getVariableByName(string $name) : Variable
    {
        return $this->variable_map[$name];
    }

    /**
     * @return array<string|int,Variable> (keys are variable names, which are *almost* always strings)
     * A map from name to Variable in this scope
     */
    public function getVariableMap() : array
    {
        return $this->variable_map;
    }

    /**
     * @param Variable $variable
     * A variable to add to the local scope
     *
     * @return Scope a clone of this scope with $variable added
     */
    public function withVariable(Variable $variable) : Scope
    {
        $scope = clone($this);
        $scope->addVariable($variable);
        return $scope;
    }

    /**
     * @param string $variable_name
     * The name of a variable to unset in the local scope
     *
     * @return Scope
     *
     * TODO: Make this work properly and merge properly when the variable is in a branch
     *
     * @suppress PhanUnreferencedPublicMethod unused, but adding to be consistent with `withVariable`
     */
    public function withUnsetVariable(string $variable_name) : Scope
    {
        $scope = clone($this);
        $scope->unsetVariable($variable_name);
        return $scope;
    }

    /**
     * @param string $variable_name
     * The name of a variable to unset in the local scope
     *
     * @return void
     *
     * TODO: Make this work properly and merge properly when the variable is in a branch (BranchScope)
     */
    public function unsetVariable(string $variable_name)
    {
        unset($this->variable_map[$variable_name]);
    }

    /**
     * Add $variable to the current scope.
     *
     * @see $this->withVariable() for creating a clone of a scope with $variable instead
     * @return void
     */
    public function addVariable(Variable $variable)
    {
        // uncomment to debug issues with variadics
        /*
        if ($variable->isVariadic() && !$variable->isCloneOfVariadic()) {
            throw new \Error("Bad variable {$variable->getName()}\n");
        }
         */
        $this->variable_map[$variable->getName()] = $variable;
    }

    /**
     * Add $variable to the set of global variables
     *
     * @param Variable $variable
     * A variable to add to the set of global variables
     *
     * @return void
     */
    public function addGlobalVariable(Variable $variable)
    {
        if (!$this->hasParentScope()) {
            throw new AssertionError("No global scope available. This should not happen.");
        }

        $this->getParentScope()->addGlobalVariable($variable);
    }

    /**
     * @return bool
     * True if a global variable with the given name exists
     */
    public function hasGlobalVariableWithName(string $name) : bool
    {
        if (!$this->hasParentScope()) {
            throw new AssertionError("No global scope available. This should not happen.");
        }

        return $this->getParentScope()->hasGlobalVariableWithName($name);
    }

    /**
     * @return Variable
     * The global variable with the given name
     */
    public function getGlobalVariableByName(string $name) : Variable
    {
        if (!$this->hasParentScope()) {
            throw new AssertionError("No global scope available. This should not happen.");
        }

        return $this->getParentScope()->getGlobalVariableByName($name);
    }

    /**
     * @return bool
     * True if there are any template types parameterizing a
     * generic class in this scope.
     */
    public function hasAnyTemplateType() : bool
    {
        if (!Config::getValue('generic_types_enabled')) {
            return false;
        }

        return \count($this->template_type_map) > 0
            || ($this->hasParentScope() && $this->getParentScope()->hasAnyTemplateType());
    }

    /**
     * @return array<string,TemplateType>
     * The set of all template types parameterizing this generic
     * class
     */
    public function getTemplateTypeMap() : array
    {
        return \array_merge(
            $this->template_type_map,
            $this->hasParentScope()
                ? $this->getParentScope()->getTemplateTypeMap()
                : []
        );
    }

    /**
     * @return bool
     * True if the given template type identifier is defined within
     * this context
     */
    public function hasTemplateType(
        string $template_type_identifier
    ) : bool {

        return isset(
            $this->template_type_map[$template_type_identifier]
        ) || ($this->hasParentScope() ? $this->getParentScope()->hasTemplateType(
            $template_type_identifier
        ) : false);
    }

    /**
     * Adds a template type to the current scope.
     *
     * The TemplateType is resolved during analysis based on the passed in union types
     * for the parameters (e.g. of __construct()) using those template types
     *
     * @param TemplateType $template_type
     * A template type parameterizing the generic class in scope
     *
     * @return void
     */
    public function addTemplateType(TemplateType $template_type)
    {
        $this->template_type_map[$template_type->getName()] = $template_type;
    }

    /**
     * @param string $template_type_identifier
     * The identifier for a generic type
     *
     * @return TemplateType
     * A TemplateType parameterizing the generic class in scope
     */
    public function getTemplateType(
        string $template_type_identifier
    ) : TemplateType {

        if (!$this->hasTemplateType($template_type_identifier)) {
            throw new AssertionError("Cannot get template type with identifier $template_type_identifier");
        }

        return $this->template_type_map[$template_type_identifier]
            ?? $this->getParentScope()->getTemplateType(
                $template_type_identifier
            );
    }

    /**
     * @return string
     * A string representation of this scope
     */
    public function __toString() : string
    {
        return $this->getFQSEN() . "\t" . implode(',', $this->getVariableMap());
    }
}
