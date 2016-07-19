<?php

namespace AppBundle\Controller;

use AppBundle\Entity\User;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

class QQLoginController extends Controller
{
    const CLIENT_ID = '101290951';
    const CLIENT_SECRET = 'fdb9b29d160948c8b7fb01a9a657f47e';

    /**
     * @Route("/auth/qq", name="qq_login", methods={"GET"})
     */
    public function loginAction(Request $request)
    {
        $state = md5(uniqid(rand(), true));
        $request->getSession()->set('state', $state);

        $params = array(
            'response_type' => 'code', //授权类型，此值固定为“code”。
            'client_id' => self::CLIENT_ID, //请QQ登录成功后，分配给应用的appid。
            'redirect_uri' => 'http://demo2016.ngrok.cc/auth/qq/callback', //成功授权后的回调地址，必须是注册appid时填写的主域名下的地址，建议设置为网站首页或网站的用户中心。注意需要将url进行URLEncode。
            'state' => $state, //client端的状态值。用于第三方应用防止CSRF攻击，成功授权后回调时会原样带回。请务必严格按照流程检查用户与state参数状态的绑定。
        );

        $url = 'https://graph.qq.com/oauth2.0/authorize?' . http_build_query($params);
        return $this->redirect($url);
    }

    /**
     * @Route("/auth/qq/callback", name="qq_callback", methods={"GET"})
     */
    public function callbackAction(Request $request)
    {
        $code = $this->getAuthorizationCode($request);

        if ($this->getState($request) !== $request->getSession()->get('state')) {
            $this->redirect($this->generateUrl('users_login'));
        }

        $token = $this->getAccessToken($code);

        $openId = $this->getOpenId($token);

        $qqUser = $this->getUserInfo($token, $openId);

        $em = $this->getDoctrine()->getManager();
        $user = $em->getRepository('AppBundle:User')->findOneBy(array('openId' => $openId, 'provider' => 'QQ'));

        if ($user == null) {
            $user = new User();
            $user->setName($qqUser->nickname);
            $user->setOpenId($openId);
            $user->setProvider('QQ');
            $em->persist($user);
            $em->flush();
        }

        $session = $request->getSession();
        $session->set('id', $user->getId());
        if ($session->get('back_url') != null) {
            return $this->redirect($session->get('back_url'));
        }

        return $this->redirect($this->generateUrl('homepage'));
    }

    private function getAuthorizationCode(Request $request)
    {
        return $request->query->get('code');
    }

    private function getState(Request $request)
    {
        return $request->query->get('state');
    }

    private function getAccessToken($code)
    {
        $params = array(
            'grant_type' => 'authorization_code', //授权类型，在本步骤中，此值为“authorization_code”。
            'client_id' => self::CLIENT_ID, //申请QQ登录成功后，分配给网站的appid。
            'client_secret' => self::CLIENT_SECRET, //申请QQ登录成功后，分配给网站的appkey。
            'code' => $code, //上一步返回的authorization code。
            'redirect_uri' => 'http://demo2016.ngrok.cc/auth/qq/callback' //与上面一步中传入的redirect_uri保持一致。和code一起用于认证服务器验证信息来源。
        );

        $url = 'https://graph.qq.com/oauth2.0/token?' . http_build_query($params);

        $res = $this->get('guzzle.client')->get($url)->send();

        //access_token=CEF4918E9614F9C1307DCA3FFC32BE55&expires_in=7776000&refresh_token=A6E855EB50CCB6F14BB37D87D413550B
        $resBody = $res->getBody();

        return explode('=', explode('&', $resBody)[0])[1];
    }

    private function getOpenId($token) {
        $url = 'https://graph.qq.com/oauth2.0/me?access_token=' . $token;
        $res = $this->get('guzzle.client')->get($url)->send();

        //callback( {"client_id":"YOUR_APPID","openid":"YOUR_OPENID"} );
        $resBody = $res->getBody();

        if (false !== strpos($resBody, "callback"))
        {
            $lpos = strpos($resBody, "(");
            $rpos = strrpos($resBody, ")");
            $resBody  = substr($resBody, $lpos + 1, $rpos - $lpos -1);
        }

        $msgObj = json_decode($resBody);

        return $msgObj->openid;
    }

    private function getUserInfo($token, $openId) {
        $queryParams = array(
            'access_token' => $token,
            'oauth_consumer_key' => self::CLIENT_ID,
            'openid' => $openId,
        );

        $url = 'https://graph.qq.com/user/get_user_info?' . http_build_query($queryParams);
        $res = $this->get('guzzle.client')->get($url)->send();
        $resBody= $res->getBody();

        $userObj = json_decode($resBody);

        return $userObj;
    }
}
