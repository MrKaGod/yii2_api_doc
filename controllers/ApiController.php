<?php
namespace app\modules\doc\controllers;
use yii\web\Controller;

class ApiController extends Controller {

    /**
     * @desc 未实现
     * @return string
     */
    public function actionIndex()
    {
        \Yii::$app->response->format = 'html';

        $routes =  $this->getControllerNotes();

        $routes = array_merge($routes, $this->getModulesControllerNotes());

        // 删除为空注释
        foreach($routes as $actionLink => $actionNotes)
        {
            foreach($actionNotes as $item => $value)
            {
                if(empty($value))
                {
                    unset($routes[$actionLink][$item]);
                }
            }
        }

        return $this->renderPartial('index',[
            'routes'=>$routes
        ]);
    }

    protected function getModulesControllerNotes()
    {
        $actionComments = [];
        $basePath = \Yii::$app->basePath.'/modules/';

        $filePath = $this->traverse($basePath);

        foreach($filePath as $value)
        {
            preg_match('/\/api\/modules\/+(.*)\/controllers/', $value, $nameSpace);

            $moduleName = $nameSpace[1];
            $nameSpace = 'app\modules\\'.$nameSpace[1].'\controllers';

            $actionComments = array_merge($actionComments, $this->getFile($value, $nameSpace, $moduleName));
        }

        return $actionComments;
    }

    /**
     * 遍历获取文件夹内容
     * @param string $path
     * @return array
     */
    protected function traverse($path) {
        $list = array();
        $list[] = $path;
        $filePath = [];

        while (count($list) > 0)
        {
            //弹出数组最后一个元素
            $file = array_pop($list);

            if(strpos($file, 'Controller.php'))
                $filePath[] = $file;

            //如果是目录
            if (is_dir($file))
            {
                $children = scandir($file);
                foreach ($children as $child)
                {
                    if ($child !== '.' && $child !== '..')
                    {
                        $list[] = $file.'/'.$child;
                    }
                }
            }
        }

        return $filePath;
    }

    /**
     * 获取控制器注释
     * @return array
     */
    protected function getControllerNotes()
    {
        try
        {
            $basePath = \Yii::$app->basePath.'/';

            $actionComments = [];

            $controllersPath = $basePath.'controllers';
            $allFiles = scandir($controllersPath);

            foreach($allFiles as $fileName)
            {
                if(strpos($fileName, 'Controller.php'))
                {
                    $filePath = $controllersPath.'/'.$fileName;

                    $actionComments = array_merge($actionComments, $this->getFile($filePath, 'app\controllers'));
                }
            }

            return $actionComments;
        }
        catch (\Exception $exception)
        {
            print_r($exception);
        }
    }

    private function getFile($filePath, $fileNameSpace, $modulesName = null)
    {
        $actionComments = [];

        $fileResult = fopen($filePath, 'r');
        $fileContent = str_replace("\r\n","<br />", fread($fileResult, filesize($filePath)));

        // 获取当前控制器名称和所有方法名称
        preg_match('/(class).+.extends/', $fileContent, $className);
        $className = explode(' ', $className[0])[1];
        $ctrl = $fileNameSpace . '\\' . $className;

        $ref = new \ReflectionClass($ctrl);
        $methods = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);

        if($methods)
        {
            foreach($methods as $method)
            {

                if (!preg_match("/^action/", $method->name) or $method->name === 'actionClientValidate' or $method->name === 'actions')
                {
                    continue;
                }
                $actionComment = $this->parseComment($method->getDocComment());
                $ctrlName = $this->parseControllerId($className);

                foreach($actionComment as $item => $comment)
                {
                    $actionComment['actionName'] = $method->name;
                    $routeMethodName = $this->parseActionId($method->name);

                    $actionComment['actionBase'] = $ctrlName;
                    $actionComment['actionLink'] = '/'.$ctrlName.'/'.$routeMethodName;

                    if(!empty($modulesName))
                        $actionComment['actionLink'] = '/'.$modulesName.$actionComment['actionLink'];

                }

                $keyName = $ctrlName;
                if (!empty($modulesName))
                    $keyName = $keyName.'/'.$modulesName;
                $keyName = $keyName.'/*';

                $actionComments[$keyName][] = $actionComment;
            }
        }

        return $actionComments;
    }

    private function parseComment($str)
    {
        $arr = explode('\n', $str);
        $comments = [];

        foreach ($arr as $comment) {
            if(!empty($comment))
            {
                $comment = explode('/**', $comment);
                $comment = explode('*/', $comment[1]);
                $comment = explode('*', $comment[0]);

                $comment = $this->getCommentInfo($comment);
                if($comment['desc'] == '未实现')
                    continue;
            }
            else
            {
                $comment = [
                    'desc' => '//请使用@desc 注释'
                ];
            }

            $comments = array_merge($comments, $comment);
        }

        return $comments;
    }

    private function getCommentInfo($comment)
    {
        foreach($comment as $lineContent)
        {
            $lineContent = trim($lineContent);
            if($lineContent == null)
                continue;

            if(preg_match('/(@desc)(.).+/', $lineContent))
            {
                $desc = explode(' ', $lineContent);

                $comments['desc'] = $desc[1];
            }
            else if(preg_match('/(@method)(.).+/', $lineContent))
            {
                $method = explode(' ', $lineContent);

                $comments['method'] = $method[1];
            }
            else if(preg_match('/(@param)(.).+/', $lineContent))
            {
                $param = explode(' ', $lineContent);

                $comments['param'][] = [
                    'type' => $param[1],
                    'data' => $param[2],
                    'content' => $param[3]
                ];
            }
            else if(preg_match('/(@return)(.).+/', $lineContent))
            {
                $return = explode(' ', $lineContent);

                $comments['return'][] = [
                    'type' => $return[1],
                    'data' => $return[2],
                    'content' => $return[3]
                ];
            }
            else if(preg_match('/(@throw)(.).+/', $lineContent))
            {
                continue;
            }
            else
            {
                $comments['title'] = $lineContent;

                if(empty($comments['desc']))
                    $comments['desc'] = '//请使用@desc 注释';
            }
        }

        return $comments;
    }

    /**
     * 将action的名称转化成路由形式
     * @param $actionId
     * @return string
     */
    private function parseActionId($actionId)
    {
        /*
        preg_match_all("/([a-zA-Z]{1}[a-z]*)?[^A-Z]/",$str,$array);
        */
        $str = preg_replace("/(?=[A-Z])/", '-', $actionId);
        $str = strtolower($str);
        $arr = explode('-', $str);
        if ($arr[0] === 'action') {
            array_shift($arr);
        }

        return implode('-',$arr);
    }

    /**
     * 将controller 名称转化成路由形式
     * @param $controllerId
     * @return string
     */
    private function parseControllerId($controllerId)
    {
        /*
        preg_match_all("/([a-zA-Z]{1}[a-z]*)?[^A-Z]/",$str,$array);
        */
        $str = preg_replace("/(?=[A-Z])/", '-', $controllerId);
        $str  = trim($str,'-');
        $str = strtolower($str);
        $arr = explode('-', $str);
        $last = count($arr)-1;
        //print_r($arr);exit;
        if ($arr[$last] === 'controller') {
            unset($arr[$last]);
        }

        //print_r($arr);exit;
        return implode('-',$arr);
    }
}