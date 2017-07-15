<?php
namespace MinorWork;

use PHPUnit\Framework\TestCase;

class AppTest extends TestCase
{
    /**
     */
    public function testSet()
    {
        $app = new App;

        // test factory function
        $invokeCnt = 0;
        $factory = function() use (&$invokeCnt) {
            $invokeCnt++;
            return ['A' => 4, 'B' => 2];
        };

        $app->a = $factory;
        $this->assertEquals(['A' => 4, 'B' => 2], $app->a);
        $this->assertEquals(['A' => 4, 'B' => 2], $app->a);

        $this->assertEquals(1, $invokeCnt, 'Factory function should only be called once');

        // test class name
        $app->b = '\stdClass';
        $this->assertInstanceOf(\stdClass::class, $app->b);

        // test simple set
        $app->c = 'MIEWMIEWMIEW';
        $this->assertEquals('MIEWMIEWMIEW', $app->c);
    }

    public function testHandlerAlias()
    {
        $aCalled = false;

        $app = new App();
        $app->handlerAlias(['a' => function () use (&$aCalled) { $aCalled = true;},]);
        $app->setRouting(['routea' => ['a']]);
        $app->runAs('routea');

        $this->assertTrue($aCalled);
    }

    /**
     * @dataProvider routeProvider
     */
    public function testRoute($routes, $name, $expectedParams, $path)
    {
        if (null === $path) {
            return;
        }

        $app = new App();
        $app->setRouting($routes);

        $routeInfo = $app->route('GET', $path);
        if (null === $name) {
            $this->assertNull($routeInfo);
        } else {
            list($parsedName, $parsedParams) = $routeInfo;
            $expectedHandler = end($routes[$name]);
            $parsedHandler = end($routes[$parsedName]);
            $this->assertEquals($parsedName, $name);
            $this->assertEquals($parsedParams, $expectedParams);
            $this->assertEquals($parsedHandler, $expectedHandler);
        }
    }

    public function routeProvider()
    {
        $routes = $this->routes();
        return [
            [$routes, 'a', ['paramB' => 'YX', 'paramA' => 'XY'], '/a/XY/YX'],
            [$routes, 'b', ['paramB' => 'YX', 'paramA' => 'XY', 'paramC' => 'C'], '/b/XY/YX/C'],
            [$routes, 'b', ['paramA' => 'XY'], '/b/XY'],
            [$routes, 'd', [], '/d'],
            [$routes, null, ['paramB' => 'XY'], '/not/exists'],
        ];
    }

    public function routeProviderWithQueryString()
    {
        $routes = $this->routeProvider();
        foreach ($routes as &$route) {
            $query = ['query' => 'string', 'route' => $route[1] ?: 'null'];
            $route[3] .= '?' . http_build_query($query);
            $route[] = $query;
        }
        return $routes;
    }

    /**
     */
    public function testMultipleHandler()
    {
        $app = new App();
        $app->setRouting($this->routes());

        $params = ['a' => 42, 'B' => 'John'];

        ob_start();
        $app->runAs('multihandler', $params);
        $output = ob_get_clean();

        $this->assertTrue($app->handler1, "1st handler should execute.");
        $this->assertEquals($params, $app->handlerParams1);
        $this->assertEquals(null, $app->handlerPrevOutput1);

        $this->assertTrue($app->handler2, "2nd handler should execute.");
        $this->assertEquals($params, $app->handlerParams2);
        $this->assertEquals(['a' => 4], $app->handlerPrevOutput2);

        $this->assertTrue($app->handler3, "3rd handler should execute.");
        $this->assertEquals($params, $app->handlerParams3);
        $this->assertEquals(['a' => 4, 'b' => 2], $app->handlerPrevOutput3);

        $this->assertEquals('{"a":4,"b":2}', $output, "Should render json");
    }

    public function testRunAs()
    {
        $aCalled = false;

        $app = new App();
        $app->setRouting(['a' => [function() use (&$aCalled) {$aCalled = true;}]]);

        $app->runAs('a');
        $this->assertTrue($aCalled);

        $this->expectException(\Exception::class);
        $app->runAs('non_exists_route');
    }

    /**
     * @dataProvider routeProvider
     * @dataProvider routeProviderWithQueryString
     */
    public function testRun($routes, $name, $expectedParams, $path)
    {
        $app = new App();
        $app->setRouting($routes);

        ob_start();
        $parsedParams = $app->run([
            'method' => 'GET',
            'uri' => $path,
        ]);
        ob_end_clean();

        if (null === $name) {
            $this->assertNull($parsedParams);
            $this->assertEquals(404, http_response_code(), "No match found, default handler should send 404 status code");
        } else {
            $this->assertEquals($parsedParams, $expectedParams);
        }
    }

    /**
     * @dataProvider routeProvider
     * @dataProvider routeProviderWithQueryString
     * @dataProvider badRoutePathProvider
     */
    public function testRoutePath($routes, $name, $params, $expectedPath, $query = [])
    {
        $app = new App();
        $app->setRouting($routes);

        if (null === $name) {
            $name = 'not_exist_route';
            $this->expectException(\Exception::class, "Throw exception when route does not exists.");
        }

        if (null === $expectedPath) {
            $this->expectException(\Exception::class, "Throw exception when did not provide enough params to creath actual path");
        }

        $actualPath = $app->routePath($name, $params, $query);

        $this->assertEquals($expectedPath, $actualPath);
    }

    public function badRoutePathProvider()
    {
        $routes = $this->routes();
        return [
            [$routes, 'a', [], null, []],
        ];
    }

    /**
     * @dataProvider routeProvider
     * @dataProvider routeProviderWithQueryString
     * @dataProvider badRedirectToProvider
     */
    public function testRedirectTo($routes, $name, $params, $expectedPath, $query = [])
    {
        if (null === $name) {
            $name = 'not_exist_route';
            $this->expectException(\Exception::class, "Throw exception when route does not exists.");
        }

        if (null === $expectedPath) {
            $this->expectException(\Exception::class, "Throw exception when did not provide enough params to creath actual path");
        }

        $app = new App();
        $app->setRouting($routes);

        $app->redirectTo($name, $params, $query);
        $this->assertContains("Location: {$expectedPath}", getHeaders());
        header_remove();
    }

    public function badRedirectToProvider()
    {
        $routes = $this->routes();
        return [
            [$routes, 'a', [], null, []],
        ];
    }

    /**
     * @dataProvider executeHandlerProvider
     */
    public function testExecuteHandler($alias, $handler, $excpectedOutput, $shouldThrowException)
    {
        $app = new App();
        $app->handlerAlias($alias);

        if ($shouldThrowException) {
            $this->expectException(\Exception::class);
        }

        $output = $app->executeHandler($handler, []);
        $this->assertEquals($excpectedOutput, $output);
    }

    public function executeHandlerProvider()
    {
        $alias = ['a' => '\MinorWork\MockController:sw'];
        return [
            // [alias list, handler, return value, throws exception]
            [$alias, function(){return 'Wonderful';}, 'Wonderful', false],
            [$alias, '\MinorWork\MockController:sw', 'Star Wars', false],
            [$alias, '\MinorWork\MockController:st', 'Star Trek', false],
            [$alias, 'a', 'Star Wars', null],
            [$alias, 'b', null, true],
            [$alias, new \stdClass, null, true],
        ];
    }

    private function routes()
    {
        $echo = function($a, $p){return $p;};
        return [
            'a' => ['/a/{paramA}/{paramB}', $echo],
            'b' => ['/b/{paramA}[/{paramB}[/{paramC}]]', $echo],
            'c' => ['/basic/path', $echo],
            'd' => [$echo],
            'multihandler' => [[
                function($a, $p, $po) {
                    $a->handler1 = true;
                    $a->handlerParams1 = $p;
                    $a->handlerPrevOutput1 = $po;
                    return ['a'=>4];
                },
                function($a, $p, $po) {
                    $a->handler2 = true;
                    $a->handlerParams2 = $p;
                    $a->handlerPrevOutput2 = $po;
                    return $po + ['b' => 2];
                },
                function($a, $p, $po) {
                    $a->handler3 = true;
                    $a->handlerParams3 = $p;
                    $a->handlerPrevOutput3 = $po;
                    $a->view->prepare(json_encode($po));
                    $a->stop();
                },
                function($a, $p){
                    throw new \Exception("Already stopped, third handler should not be called!");
                },
            ]],
        ];
    }
}

class MockController
{
    public function st(){ return "Star Trek";}
    public function sw(){ return "Star Wars";}
}
