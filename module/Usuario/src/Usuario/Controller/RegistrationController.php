<?php
namespace Usuario\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Usuario\Entity\Usuario;

// a test class in a coolcsn namespace for installer. You can remove the next line
use CsnBase\Zend\Validator\ConfirmPassword;

// Doctrine Annotations
use DoctrineModule\Stdlib\Hydrator\DoctrineObject as DoctrineHydrator;
use DoctrineORMModule\Stdlib\Hydrator\DoctrineEntity;
use DoctrineORMModule\Form\Annotation\AnnotationBuilder as DoctrineAnnotationBuilder;

// Zend Annotation
use Zend\Form\Annotation\AnnotationBuilder;
// for the form
use Zend\Form\Element;
use Usuario\Form\RegistrationForm;
use Usuario\Form\RegistrationFilter;
use Usuario\Form\ForgottenPasswordForm;
use Usuario\Form\ForgottenPasswordFilter;
use Zend\Mail\Message;

class RegistrationController extends AbstractActionController
{

    protected $usuario;

    public function indexAction()
    {
        // change layout
        $this->layout('layout/custom');
        
        $entityManager = $this->getServiceLocator()->get('doctrine.entitymanager.orm_default');
        $user = new Usuario();
        // 1) A lot of work to manualy change the form add fields etc. Better use a form class
        // - $form = $this->getRegistrationForm($entityManager, $user);
        
        // 2) Better use a form class
        $form = new RegistrationForm();
        $form->get('submit')->setValue('Register');
        $form->setHydrator(new DoctrineHydrator($entityManager, 'Usuario\Entity\Usuario'));
        
        $form->bind($user);
        $request = $this->getRequest();
        
        if ($request->isPost()) {
            
            $form->setInputFilter(new RegistrationFilter($this->getServiceLocator()));
            $form->setData($request->getPost());
            if ($form->isValid()) {
                
                $user->setPermissao('usuario');
                $user->setUsrEmailConfirmed($user->getEmail());
                $this->prepareData($user);
                $this->sendConfirmationEmail($user);
                $this->flashMessenger()->addMessage($user->getEmail());
                $entityManager->persist($user);
                $entityManager->flush();
                
                $this->usuario = $user;
                
                return $this->redirect()->toRoute('registration/registration-success', array(
                    'controller' => 'registration',
                    'action' => 'registration-success'
                ));
            }
        }
        return new ViewModel(array(
            'form' => $form
        ));
    }

    public function registrationSuccessAction()
    {
        $email = null;
        $flashMessenger = $this->flashMessenger();
        
        if ($flashMessenger->hasMessages()) {
            foreach ($flashMessenger->getMessages() as $key => $value) {
                
                $email .= $value;
            }
        }
        
        return new ViewModel(array(
            'email' => $email
        ));
    }

    public function confirmEmailAction()
    {
        $token = $this->params()->fromRoute('id');
        
        $viewModel = new ViewModel(array(
            'token' => $token
        ));
        try {
            if (empty($token)) {
                
                $viewModel->setTemplate('Usuario/registration/confirm-email-error.phtml');
                return $viewModel;
            }
            
            $entityManager = $this->getServiceLocator()->get('doctrine.entitymanager.orm_default');
            $user = $entityManager->getRepository('Usuario\Entity\Usuario')->findOneBy(array(
                'usrRegistrationToken' => $token
            )); //
            
            $user->setUsrActive(1);
            $user->setUsrEmailConfirmed(1);
            $entityManager->persist($user);
            $entityManager->flush();
        } catch (\Exception $e) {
            
            $viewModel->setTemplate('Usuario/registration/confirm-email-error.phtml');
        }
        return $viewModel;
    }

    public function forgottenPasswordAction()
    {
        $form = new ForgottenPasswordForm();
        $form->get('submit')->setValue('Send');
        $request = $this->getRequest();
        if ($request->isPost()) {
            $form->setInputFilter(new ForgottenPasswordFilter($this->getServiceLocator()));
            $form->setData($request->getPost());
            
            if ($form->isValid()) {
                $data = $form->getData();
                
                $email = $data['email'];
                $entityManager = $this->getServiceLocator()->get('doctrine.entitymanager.orm_default');
                $user = $entityManager->getRepository('Usuario\Entity\Usuario')->findOneBy(array(
                    'email' => $email
                )); //
                
                $password = $this->generatePassword();
                $passwordHash = $this->encriptPassword($this->getStaticSalt(), $password, $user->getPasswordsalt());
                $this->sendPasswordByEmail($email, $password);
                $this->flashMessenger()->addMessage($email);
                $user->setPassword($passwordHash);
                $entityManager->persist($user);
                $entityManager->flush();
                return $this->redirect()->toRoute('index/logar', array(
                    'controller' => 'index',
                    'action' => 'login'
                ));
            }
        }
        return new ViewModel(array(
            'form' => $form
        ));
    }

    public function passwordChangeSuccessAction()
    {
        $email = null;
        $flashMessenger = $this->flashMessenger();
        if ($flashMessenger->hasMessages()) {
            foreach ($flashMessenger->getMessages() as $key => $value) {
                $email .= $value;
            }
        }
        return new ViewModel(array(
            'email' => $email
        ));
    }

    public function prepareData($user)
    {
        // Eu mesmo comentei
        // print "<pre>"; print_r($user);
        // die();
        // $user->set
        $user->setUsrActive(0);
        $user->setPasswordsalt($this->generateDynamicSalt());
        $user->setPassword($this->encriptPassword($this->getStaticSalt(), $user->getPassword(), $user->getPasswordsalt()));
        // $user->setUsrlId(2);
        // $user->setLngId(1);
        // $user->setUsrRegistrationDate(new \DateTime());
        $user->setUsrRegistrationToken(md5(uniqid(mt_rand(), true))); // $this->generateDynamicSalt();
                                                                      // // $user->setUsrRegistrationToken(uniqid(php_uname('n'), true));
                                                                      // $user->setUsrEmailConfirmed(0);
        return $user;
    }

    public function generateDynamicSalt()
    {
        $dynamicSalt = '';
        for ($i = 0; $i < 50; $i ++) {
            $dynamicSalt .= chr(rand(33, 126));
        }
        return $dynamicSalt;
    }

    public function getStaticSalt()
    {
        $staticSalt = '';
        $config = $this->getServiceLocator()->get('Config');
        $staticSalt = $config['static_salt'];
        return $staticSalt;
    }

    public function encriptPassword($staticSalt, $password, $dynamicSalt)
    {
        return $password = md5($staticSalt . $password . $dynamicSalt);
    }

    public function generatePassword($l = 8, $c = 0, $n = 0, $s = 0)
    {
        // get count of all required minimum special chars
        $count = $c + $n + $s;
        $out = '';
        // sanitize inputs; should be self-explanatory
        if (! is_int($l) || ! is_int($c) || ! is_int($n) || ! is_int($s)) {
            trigger_error('Argument(s) not an integer', E_USER_WARNING);
            return false;
        } elseif ($l < 0 || $l > 20 || $c < 0 || $n < 0 || $s < 0) {
            trigger_error('Argument(s) out of range', E_USER_WARNING);
            return false;
        } elseif ($c > $l) {
            trigger_error('Number of password capitals required exceeds password length', E_USER_WARNING);
            return false;
        } elseif ($n > $l) {
            trigger_error('Number of password numerals exceeds password length', E_USER_WARNING);
            return false;
        } elseif ($s > $l) {
            trigger_error('Number of password capitals exceeds password length', E_USER_WARNING);
            return false;
        } elseif ($count > $l) {
            trigger_error('Number of password special characters exceeds specified password length', E_USER_WARNING);
            return false;
        }
        
        // all inputs clean, proceed to build password
        
        // change these strings if you want to include or exclude possible password characters
        $chars = "abcdefghijklmnopqrstuvwxyz";
        $caps = strtoupper($chars);
        $nums = "0123456789";
        $syms = "!@#$%^&*()-+?";
        
        // build the base password of all lower-case letters
        for ($i = 0; $i < $l; $i ++) {
            $out .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        
        // create arrays if special character(s) required
        if ($count) {
            // split base password to array; create special chars array
            $tmp1 = str_split($out);
            $tmp2 = array();
            
            // add required special character(s) to second array
            for ($i = 0; $i < $c; $i ++) {
                array_push($tmp2, substr($caps, mt_rand(0, strlen($caps) - 1), 1));
            }
            for ($i = 0; $i < $n; $i ++) {
                array_push($tmp2, substr($nums, mt_rand(0, strlen($nums) - 1), 1));
            }
            for ($i = 0; $i < $s; $i ++) {
                array_push($tmp2, substr($syms, mt_rand(0, strlen($syms) - 1), 1));
            }
            
            // hack off a chunk of the base password array that's as big as the special chars array
            $tmp1 = array_slice($tmp1, 0, $l - $count);
            // merge special character(s) array with base password array
            $tmp1 = array_merge($tmp1, $tmp2);
            // mix the characters up
            shuffle($tmp1);
            // convert to string for output
            $out = implode('', $tmp1);
        }
        
        return $out;
    }

    public function getUsersTable()
    {
        if (! $this->usersTable) {
            $sm = $this->getServiceLocator();
            $this->usersTable = $sm->get('Auth\Model\UsersTable');
        }
        return $this->usersTable;
    }

    public function sendConfirmationEmail($user)
    {
        // $view = $this->getServiceLocator()->get('View');
        $transport = $this->getServiceLocator()->get('mail.transport');
        $message = new Message();
        $this->getRequest()->getServer(); // Server vars
        
        $message->addTo($user->getEmail())
            ->addFrom('david.abraao.petro@gmail.com')
            ->setSubject('Please, confirm your registration!')
            ->
        // ->setBody("Please, click the link to confirm your registration => " . $this->getRequest()
        // ->getServer('HTTP_ORIGIN') . $this->url()
        setBody("Please, click the link to confirm your registration => http://www." . $this->getRequest()
            ->getServer('HTTP_HOST') . $this->url()
            ->fromRoute('registration/confirm-email', array(
            'controller' => 'registration',
            'action' => 'confirm-email',
            'id' => $user->getUsrRegistrationToken()
        )));
        
        // $this->acploUrl()->from('usuario/confirm-email',['id'=>$user->getUsrRegistrationToken()], true)
        
        $transport->send($message);
    }

    public function sendPasswordByEmail($usr_email, $password)
    {
        $transport = $this->getServiceLocator()->get('mail.transport');
        $message = new Message();
        $this->getRequest()->getServer(); // Server vars
        $message->addTo($usr_email)
            ->addFrom('david.abraao.petro@gmail.com')
            ->setSubject('Your password has been changed!')
            ->setBody("Your password at  " . $this->getRequest()
            ->getServer('HTTP_ORIGIN') . ' has been changed. Your new password is: ' . $password);
        $transport->send($message);
    }
    
    // ToDo Ask yourself
    // 1) do we need a separate Entity Registration to handle registration
    // 2) do we have to use form
    // 3) do we have to use User Entity and do what we are doing here. Manually adding removing elements
    // Is not completed
    public function getRegistrationForm($entityManager, $user)
    {
        $builder = new DoctrineAnnotationBuilder($entityManager);
        $form = $builder->createForm($user);
        $form->setHydrator(new DoctrineHydrator($entityManager, 'Usuario\Entity\Usuario'));
        $filter = $form->getInputFilter();
        $form->remove('usrlId');
        $form->remove('lngId');
        $form->remove('usrActive');
        $form->remove('usrQuestion');
        $form->remove('usrAnswer');
        $form->remove('usrPicture');
        $form->remove('usrPasswordSalt');
        $form->remove('usrRegistrationDate');
        $form->remove('usrRegistrationToken');
        $form->remove('usrEmailConfirmed');
        
        // ... A lot of work of manually building the form
        
        $form->add(array(
            'name' => 'usrPasswordConfirm',
            'attributes' => array(
                'type' => 'password'
            ),
            'options' => array(
                'label' => 'Confirm Password'
            )
        ));
        
        $form->add(array(
            'type' => 'Zend\Form\Element\Captcha',
            'name' => 'captcha',
            'options' => array(
                'label' => 'Please verify you are human',
                'captcha' => new \Zend\Captcha\Figlet()
            )
        ));
        
        $send = new Element('submit');
        $send->setValue('Register'); // submit
        $send->setAttributes(array(
            'type' => 'submit'
        ));
        $form->add($send);
        // ...
        return $form;
    }
}