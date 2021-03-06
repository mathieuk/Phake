<?php
/* 
 * Phake - Mocking Framework
 * 
 * Copyright (c) 2010, Mike Lively <m@digitalsandwich.com>
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 * 
 *  *  Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 * 
 *  *  Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 * 
 *  *  Neither the name of Mike Lively nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 * 
 * @category   Testing
 * @package    Phake
 * @author     Mike Lively <m@digitalsandwich.com>
 * @copyright  2010 Mike Lively <m@digitalsandwich.com>
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @link       http://www.digitalsandwich.com/
 */

require_once 'Phake/CallRecorder/Call.php';
require_once 'Phake/IMock.php';

/**
 * Creates and executes the code necessary to create a mock class.
 *
 * @author Mike Lively <m@digitalsandwich.com>
 */
class Phake_ClassGenerator_MockClass
{
	/**
	 * Generates a new class with the given class name
	 *
	 * @param string $newClassName - The name of the new class
	 * @param string $mockedClassName - The name of the class being mocked
	 * @return NULL
	 */
	public function generate($newClassName, $mockedClassName)
	{
		if (class_exists($mockedClassName, TRUE))
		{
			$extends = "extends {$mockedClassName}";
			$implements = '';
		}
		elseif (interface_exists($mockedClassName, TRUE))
		{
			$extends = '';
			$implements = ", {$mockedClassName}";
		}

		$mockedClass = new ReflectionClass($mockedClassName);

		$classDef = "
class {$newClassName} {$extends}
	implements Phake_IMock {$implements}
{
	private \$__PHAKE_callRecorder;

	private \$__PHAKE_stubMapper;

	private \$__PHAKE_defaultAnswer;

	private \$__PHAKE_isFrozen = FALSE;

	public function __construct(Phake_CallRecorder_Recorder \$callRecorder, Phake_Stubber_StubMapper \$stubMapper, Phake_Stubber_IAnswer \$defaultAnswer, array \$constructorArgs = null)
	{
		\$this->__PHAKE_callRecorder = \$callRecorder;
		\$this->__PHAKE_stubMapper = \$stubMapper;
		\$this->__PHAKE_defaultAnswer = \$defaultAnswer;
		{$this->getConstructorChaining($mockedClass)}
	}

	public function __PHAKE_getCallRecorder()
	{
		return \$this->__PHAKE_callRecorder;
	}

	public function __PHAKE_addAnswer(Phake_Stubber_IAnswer \$answer, Phake_Matchers_MethodMatcher \$matcher)
	{
		\$this->__PHAKE_stubMapper->mapStubToMatcher(\$answer, \$matcher);
	}

	public function __PHAKE_resetMock()
	{
		\$this->__PHAKE_stubMapper->removeAllAnswers();
		\$this->__PHAKE_callRecorder->removeAllCalls();
		\$this->__PHAKE_isFrozen = FALSE;
	}

	public function __PHAKE_freezeMock()
	{
		\$this->__PHAKE_isFrozen = TRUE;
	}

	public function __PHAKE_getName()
	{
		return '{$mockedClassName}';
	}

	private function __PHAKE_processAnswer(\$methodName, \$args, \$answer)
	{
		if (\$answer instanceof Phake_Stubber_Answers_IDelegator)
		{
			\$delegate = \$answer->getAnswer();
			\$callback = \$delegate->getCallBack(\$this, \$methodName, \$args);
			\$arguments = \$delegate->getArguments(\$methodName, \$args);

			\$realAnswer = call_user_func_array(\$callback, \$arguments);
			\$answer->processAnswer(\$realAnswer);
			return \$realAnswer;
		}
		else
		{
			return \$answer->getAnswer();
		}
	}

	{$this->generateMockedMethods($mockedClass)}
}
";

		eval($classDef);
	}

	/**
	 * Instantiates a new instance of the given mocked class.
	 *
	 * @param string $newClassName
	 * @param Phake_CallRecorder_Recorder $recorder
	 * @param Phake_Stubber_StubMapper $mapper
	 * @param Phake_Stubber_IAnswer $defaultAnswer
	 * @param array $constructorArgs
	 * @return Phake_IMock of type $newClassName
	 */
	public function instantiate($newClassName, Phake_CallRecorder_Recorder $recorder, Phake_Stubber_StubMapper $mapper, Phake_Stubber_IAnswer $defaultAnswer, array $constructorArgs = null)
	{
		return new $newClassName($recorder, $mapper, $defaultAnswer, $constructorArgs);
	}

	/**
	 * Generate mock implementations of all public and protected methods in the mocked class.
	 * @param ReflectionClass $mockedClass
	 * @return string
	 */
	protected function generateMockedMethods(ReflectionClass $mockedClass)
	{
		$methodDefs = '';
		$filter = ReflectionMethod::IS_ABSTRACT | ReflectionMethod::IS_PROTECTED | ReflectionMethod::IS_PUBLIC | ~ReflectionMethod::IS_FINAL;
		foreach ($mockedClass->getMethods($filter) as $method)
		{
			if (!$method->isConstructor() && !$method->isDestructor() && !$method->isFinal())
			{
				if (strtolower($method->getName()) === '__call')
				{
					$methodDefs .= $this->implementCallMethod($method);
				}
				else
				{
					$methodDefs .= $this->implementMethod($method);
				}
				$methodDefs .= "\n";
			}
		}

		return $methodDefs;
	}

	/**
	 * Creates the constructor implementation
	 */
	protected function getConstructorChaining(ReflectionClass $originalClass)
	{
		return $originalClass->hasMethod('__construct') ? "

		if (is_array(\$constructorArgs))
		{
			call_user_func_array(array(\$this, 'parent::__construct'), \$constructorArgs);
		}
		" : "";
	}

	/**
	 * Creates the implementation of a single method
	 * @param ReflectionMethod $method
	 */
	protected function implementMethod(ReflectionMethod $method)
	{
		$modifiers = implode(' ', Reflection::getModifierNames($method->getModifiers() & ~ReflectionMethod::IS_ABSTRACT));

		$methodDef = "
	{$modifiers} function {$method->getName()}({$this->generateMethodParameters($method)})
	{
		if (\$this->__PHAKE_isFrozen)
		{
			throw new Exception('This object has been frozen.');
		}

		\$methodName = \$actualMethodName = '{$method->getName()}';
		\$args = \$actualArgs = func_get_args();

		\$this->__PHAKE_callRecorder->recordCall(new Phake_CallRecorder_Call(\$this, \$methodName, \$args));

		\$stub = \$this->__PHAKE_stubMapper->getStubByCall(\$methodName, \$args);

		if (\$stub !== NULL)
		{
			\$answer = \$stub;
		}
		else
		{
			\$answer = \$this->__PHAKE_defaultAnswer;
		}

		return \$this->__PHAKE_processAnswer(\$methodName, \$args, \$answer);
	}
";

		return $methodDef;
	}

	/**
	 * Creates the implementation of __call if necessary
	 * @param ReflectionMethod $method
	 */
	protected function implementCallMethod(ReflectionMethod $method)
	{
		$modifiers = implode(' ', Reflection::getModifierNames($method->getModifiers() & ~ReflectionMethod::IS_ABSTRACT));

		$methodDef = "
	{$modifiers} function __call({$this->generateMethodParameters($method)})
	{
		if (\$this->__PHAKE_isFrozen)
		{
			throw new Exception('This object has been frozen.');
		}

		\$args = func_get_args();

		\$this->__PHAKE_callRecorder->recordCall(new Phake_CallRecorder_Call(\$this, '__call' , \$args));
		\$this->__PHAKE_callRecorder->recordCall(new Phake_CallRecorder_Call(\$this, \$args[0], \$args[1]));


		\$stub = \$this->__PHAKE_stubMapper->getStubByCall(\$args[0], \$args[1]);

		if (\$stub === NULL)
		{
			\$stub = \$this->__PHAKE_stubMapper->getStubByCall('__call', \$args);
		}

		if (\$stub !== NULL)
		{
			\$answer = \$stub;
		}
		else
		{
			\$answer = \$this->__PHAKE_defaultAnswer;
		}

		return \$this->__PHAKE_processAnswer('__call', \$args, \$answer);
	}
";

		return $methodDef;
	}

	/**
	 * Generates the code for all the parameters of a given method.
	 * @param ReflectionMethod $method
	 * @return string
	 */
	protected function generateMethodParameters(ReflectionMethod $method)
	{
		$parameters = array();
		foreach ($method->getParameters() as $parameter)
		{
			$parameters[] = $this->implementParameter($parameter);
		}

		return implode(', ', $parameters);
	}

	/**
	 * Generates the code for an individual method parameter.
	 * @param ReflectionParameter $parameter
	 * @return string
	 */
	protected function implementParameter(ReflectionParameter $parameter)
	{
		if ($parameter->isArray())
		{
			$type = 'array ';
		}
		elseif ($parameter->getClass() !== NULL)
		{
			$type = $parameter->getClass()->getName() . ' ';
		}
		else
		{
			$type = '';
		}

		if ($parameter->isDefaultValueAvailable())
		{
			$default = ' = ' . var_export($parameter->getDefaultValue(), TRUE);
		}
		else
		{
			$default = '';
		}

		return $type . ($parameter->isPassedByReference() ? '&' : '') . '$' . $parameter->getName() . $default;
	}
}

?>
