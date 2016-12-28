<?php
namespace Interactify\Bundle\IntegrationBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Oro\Bundle\SecurityBundle\Annotation\Acl;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;

class DefaultController extends Controller
{

    private function responseNotFound()
    {
        return new JsonResponse([
            'customer_info' => [
                'html' => 'Customer not found'
            ],
            'history' => []
        ]);
    }

    private function response(array $data, array $history) 
    {
        $html = $this->render('InteractifyIntegrationBundle:Default:info.html.twig', $data)->getContent();
        $html_history = $this->render('InteractifyIntegrationBundle:Default:history.html.twig', ['history' => $history])->getContent();

        return new JsonResponse([
            'customer_info' => [
                'html' => $html
            ],
            'history' => [
                'html' => $html_history
            ]
        ]);
    }

    private function findContactByEmail($email) 
    {
        $contactEmailClass = $this->container->getParameter('orocrm_contact.entity.email.class');
        $contactEmailRepository = $this->getDoctrine()->getRepository($contactEmailClass);
        $contactEmail = $contactEmailRepository->findOneBy(['email' => $email]);
        if($contactEmail !== null) {
            return $contactEmail->getOwner();
        }
    }

    private function findContactByPhone($phone) 
    {
        $contactPhoneClass = $this->container->getParameter('orocrm_contact.entity.phone.class');
        $contactPhoneRepository = $this->getDoctrine()->getRepository($contactPhoneClass);
       
        $filterCharacters = ['-',' ','(',')'];
        $generatedCondition = "p.phone";
        foreach($filterCharacters as $filterCharacter) {
            $generatedCondition = "replace(".$generatedCondition.",'". $filterCharacter . "','')";
            $phone = str_replace($filterCharacter, '', $phone);
        }
        $contactPhone = $contactPhoneRepository->createQueryBuilder('p')
            ->where("$generatedCondition=:phone")
            ->setParameter('phone', $phone)
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();
        return $contactPhone ? $contactPhone->getOwner() : null;
    }

    private function getCall($callClass,$contact,$owner,$callInfo)
    {
        $call = new $callClass();
        $call->setDuration($callInfo['duration']);
        $callStatus = $this->getDoctrine()
            ->getRepository('OroCRMCallBundle:CallStatus')
            ->findOneByName('completed');

        $call->setCallStatus($callStatus);
        $callDirection = $this->getDoctrine()
            ->getRepository('OroCRMCallBundle:CallDirection')
            ->findOneByName($callInfo['direction']);
        $call->setDirection($callDirection);
        $call->setSubject($callInfo['call_type']);
        $call->setPhoneNumber($callInfo['phone_number']);
        $call->setOwner($owner);
        $call->setNotes($callInfo['comment']);
        return $call;
    }

    /**
    * @Route("/saveinteraction/{key}/{caller_id_type}/{caller_id_value}", name="interactify_save_interaction")
    */
    public function saveInteractionAction($key,$caller_id_type,$caller_id_value) 
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();
        if (!$request->get('call_type') || !$request->get('direction')) {
            die('Required parameters missing');
        }

        if($caller_id_type == 'email') {
            $contact = $this->findContactByEmail($caller_id_value);
        } else if($caller_id_type == 'phone') {
            $contact = $this->findContactByPhone($caller_id_value);
        }
        if ($contact === null) {
            return $this->responseNotFound();
        }

        $userManager = $this->get('oro_user.manager');
        $email = $request->get('agent_email');
        $user = $userManager->findUserByEmail($email);
        if (!$user) {
            $user = $userManager->findUserByUsername('admin');
        }

        $callClass = $this->container->getParameter('orocrm_call.call.entity.class');

        $call = $this->getCall($callClass,$contact,$user,[
            'comment' => $request->get('comment'),
            'phone_number' => $caller_id_value,
            'call_type' =>  $request->get('call_type'),
            'direction' => $request->get('direction'),
            'duration' => $request->get('duration')
        ]);
        $callActivityManager = $this->get('orocrm_call.call.activity.manager');
        $callActivityManager->addAssociation($call,$contact);
        $em = $this->getDoctrine()->getEntityManager();
        $em->persist($call);
        $em->flush();

        return $this->responseNotFound();
    }

    /**
    * @Route("/interaction", name="interactify_interaction")
    */
    public function interactionAction()
    {
        return $this->render('InteractifyIntegrationBundle:Default:interaction.html.twig');
    }


    /**
    * @Route("/info/{key}/{caller_id_type}/{caller_id_value}", name="interactify_integration")
    */
    public function infoAction($key,$caller_id_type,$caller_id_value)
    {
        $provider = $this->get('interactify_integration.activities_provider');

        $validTypes = ['email' => 1, 'phone' => 1];
        if (!isset($validTypes[$caller_id_type])) {
            return $this->responseNotFound();
        }
        if($caller_id_type == 'email') {
            $contact = $this->findContactByEmail($caller_id_value);
        } else if($caller_id_type == 'phone') {
            $contact = $this->findContactByPhone($caller_id_value);
        }
        if ($contact === null) {
            return $this->responseNotFound();
        }
        $accounts = $contact->getAccounts();
        $phones = $contact->getPhones();
        $primary_phone = $alt1_phone = $alt2_phone = "";
        foreach($phones as $phone) {
            if ($phone->isPrimary()) $primary_phone = $phone->getPhone();
            else if (empty($alt1_phone)) $alt1_phone = $phone->getPhone();
            else $alt2_phone = $phone->getPhone();
        }
        $company = "";
        foreach($accounts as $account) {
            $company = $account->getName();
        }
        $addresses = $contact->getAddresses();
        $primary_address = $secondary_address = "";
        foreach($addresses as $address) {
            if($address->isPrimary()) {
                $primary_address = $address;
            } else {
                $secondary_address = $address;
            }
        }
        $emails = $contact->getEmails();
        $primary_email = $secondary_email = "";
        foreach($emails as $email) {
            if($email->isPrimary()) {
                $primary_email = $email->getEmail();
            } else {
                $secondary_email = $email->getEmail();
            }
        }

        $activitiesByClass = $provider->getActivities($contact);
        $history = [];
        foreach($activitiesByClass as $class => $activities) {
            foreach($activities as $activity) {
                $entry = [
                    'createdAt' => $activity['createdAt']->format('Y.m.d m:i'),
                    'subject' => $activity['subject']
                ];
                $history []= $entry;
            }
        }

        $data = [
            'contact' => $contact,
            'company' => $company,
            'primary_phone' => $primary_phone,
            'alt1_phone' => $alt1_phone,
            'alt2_phone' => $alt2_phone,
            'primary_address' => $primary_address,
            'secondary_address' => $secondary_address,
            'primary_email' => $primary_email,
            'secondary_email' => $secondary_email
        ];
        return $this->response($data, $history);
    }
}
