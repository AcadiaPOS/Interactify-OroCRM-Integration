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

    private function getCall($callClass,$contact,$owner,$callInfo)
    {
        $call = new $callClass();
        $call->setDuration($callInfo['duration']);
        $callStatus = $this->getDoctrine()
            ->getRepository('OroCallBundle:CallStatus')
            ->findOneByName('completed');

        $call->setCallStatus($callStatus);
        $callDirection = $this->getDoctrine()
            ->getRepository('OroCallBundle:CallDirection')
            ->findOneByName($callInfo['direction']);
        $call->setDirection($callDirection);
        $call->setSubject($callInfo['call_type']);
        $call->setPhoneNumber($callInfo['phone_number']);
        $call->setOwner($owner);
        $call->setNotes($callInfo['comment']);
        $call->setOrganization($callInfo['organization']);
        return $call;
    }

    private function findUser()
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $userManager = $this->get('oro_user.manager');
        $email = $request->get('agent_email');
        $user = $userManager->findUserByEmail($email);
        if (!$user) {
            $user = $userManager->findUserByUsername('admin');
        }
        return $user;
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

        $entityProvider = $this->get('interactify_integration.entities_provider');
        $entities = $entityProvider->getEntities($caller_id_type,$caller_id_value);

        if (!count($entities)) {
            return $this->responseNotFound();
        }

        $user = $this->findUser();

        $callClass = $this->container->getParameter('oro_call.call.entity.class');
        $orgManager = $this->get('oro_organization.organization_manager');
        $org = $orgManager->getEnabledUserOrganizationByName($user, '');

        $call = $this->getCall($callClass,$entity,$user,[
            'comment' => $request->get('comment'),
            'phone_number' => $caller_id_value,
            'call_type' =>  $request->get('call_type'),
            'direction' => $request->get('direction'),
            'duration' => $request->get('duration'),
            'organization' => $org
        ]);
        foreach($entities as $entityType => $entity) {
            $callActivityManager = $this->get('oro_call.call.activity.manager');
            $callActivityManager->addAssociation($call,$entity);
            $em = $this->getDoctrine()->getEntityManager();
            $em->persist($call);
            $em->flush();
        }
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
        $entityProvider = $this->get('interactify_integration.entities_provider');

        $validTypes = ['email' => 1, 'phone' => 1];
        if (!isset($validTypes[$caller_id_type])) {
            return $this->responseNotFound();
        }

        $entities = $entityProvider->getEntities($caller_id_type,$caller_id_value);
        if (!count($entities)) {
            return $this->responseNotFound();
        }
        foreach($entities as $entityType => $entity) {

            if($entityType == 'contact') {
                $accounts = $entity->getAccounts();
                $company = "";
                foreach($accounts as $account) {
                    $company = $account->getName();
                }
            }
            if($entityType == 'lead') {
                $company = $entity->getCompanyName();
            }
            $phones = $entity->getPhones();
            $primary_phone = $alt1_phone = $alt2_phone = "";
            foreach($phones as $phone) {
                if ($phone->isPrimary()) $primary_phone = $phone->getPhone();
                else if (empty($alt1_phone)) $alt1_phone = $phone->getPhone();
                else $alt2_phone = $phone->getPhone();
            }

            $addresses = $entity->getAddresses();
            $primary_address = $secondary_address = "";
            foreach($addresses as $address) {
                if($address->isPrimary()) {
                    $primary_address = $address;
                } else {
                    $secondary_address = $address;
                }
            }
            $emails = $entity->getEmails();
            $primary_email = $secondary_email = "";
            foreach($emails as $email) {
                if($email->isPrimary()) {
                    $primary_email = $email->getEmail();
                } else {
                    $secondary_email = $email->getEmail();
                }
            }

            $activitiesByClass = $provider->getActivities($entity);
            $history = [];
            foreach($activitiesByClass as $class => $activities) {
                foreach($activities as $activity) {
                    $entry = [
                        'createdAt' => $activity['createdAt']->format('Y-m-d m:i'),
                        'subject' => $activity['subject']
                    ];
                    $history []= $entry;
                }
            }
            uasort($history, function($a,$b) {
                return (strtotime($b['createdAt']) <=> strtotime($a['createdAt']));
            });

            $data = [
                'contact' => $entity,
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
}
