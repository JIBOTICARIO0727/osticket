<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.ticket.php';
include_once INCLUDE_DIR.'class.file.php';

class TicketApiController extends ApiController {

    # Supported arguments -- anything else is an error. These items will be
    # inspected _after_ the fixup() method of the ApiXxxDataParser classes
    # so that all supported input formats should be supported
    function getRequestStructure($format, $data=null) {
        $supported = array(
            "alert", "autorespond", "source", "topicId",
            "response" ,"userEmail", "staffUsername", "isAgent","ticket_id",
            "attachments" => array("*" =>
                array("name", "type", "data", "encoding", "size")
            ),
            "message", "ip", "priorityId",
            "system_emails" => array(
                "*" => "*"
            ),
            "thread_entry_recipients" => array (
                "*" => array("to", "cc")
            )
        );
        # Fetch dynamic form field names for the given help topic and add
        # the names to the supported request structure
        if (isset($data['topicId'])
                && ($topic = Topic::lookup($data['topicId']))
                && ($forms = $topic->getForms())) {
            foreach ($forms as $form)
                foreach ($form->getDynamicFields() as $field)
                    $supported[] = $field->get('name');
        }

        # Ticket form fields
        # TODO: Support userId for existing user
        if(($form = TicketForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        # User form fields
        if(($form = UserForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        if(!strcasecmp($format, 'email')) {
            $supported = array_merge($supported, array('header', 'mid',
                'emailId', 'to-email-id', 'ticketId', 'reply-to', 'reply-to-name',
                'in-reply-to', 'references', 'thread-type', 'system_emails',
                'mailflags' => array('bounce', 'auto-reply', 'spam', 'viral'),
                'recipients' => array('*' => array('name', 'email', 'source'))
                ));

            $supported['attachments']['*'][] = 'cid';
        }

        return $supported;
    }

    /*
     Validate data - overwrites parent's validator for additional validations.
    */
    function validate(&$data, $format, $strict=true) {
        global $ost;

        //Call parent to Validate the structure
        if(!parent::validate($data, $format, $strict) && $strict)
            $this->exerr(400, __('Unexpected or invalid data received'));

        // Use the settings on the thread entry on the ticket details
        // form to validate the attachments in the email
        $tform = TicketForm::objects()->one()->getForm();
        $messageField = $tform->getField('message');
        $fileField = $messageField->getWidget()->getAttachments();

        // Nuke attachments IF API files are not allowed.
        if (!$messageField->isAttachmentsEnabled())
            $data['attachments'] = array();

        //Validate attachments: Do error checking... soft fail - set the error and pass on the request.
        if ($data['attachments'] && is_array($data['attachments'])) {
            foreach($data['attachments'] as &$file) {
                if ($file['encoding'] && !strcasecmp($file['encoding'], 'base64')) {
                    if(!($file['data'] = base64_decode($file['data'], true)))
                        $file['error'] = sprintf(__('%s: Poorly encoded base64 data'),
                            Format::htmlchars($file['name']));
                }
                // Validate and save immediately
                try {
                    $F = $fileField->uploadAttachment($file);
                    $file['id'] = $F->getId();
                }
                catch (FileUploadError $ex) {
                    $name = $file['name'];
                    $file = array();
                    $file['error'] = Format::htmlchars($name) . ': ' . $ex->getMessage();
                }
            }
            unset($file);
        }

        return true;
    }


    function create($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $ticket = $this->createTicket($this->getRequest($format));
        }

        if(!$ticket)
            return $this->exerr(500, __("Unable to create new ticket: unknown error"));

        $this->response(201, $ticket->getNumber());
    }

    // Start of: Additional from IPI

    function reply($format) {
        try{
            # Check API Key
            if(!($key=$this->requireApiKey()))
                return $this->exerr(401, __('API key not authorized'));

            $data = $this->getRequest($format);

            # Checks required fields
            if (!$data['response'])
                return $this->exerr(400,__('Missing response parameter.'));

            if (!$data['ticket_id'])
                return $this->exerr(400, __('Missing ticket id'));

            # Checks if ticket exist
            $id = Ticket::getIdByNumber($data['ticket_id']);
            if ($id <= 0)
                return $this->response(404, __('Ticket not found'));

            $ticket=Ticket::lookup($id);
            $errors = array();

            # Checks if user or agent reply    
            if($data['isAgent'] == true){
                if (!$data['staffUserName'])
                    return $this->exerr(400, __('Missing Staff username parameter'));

                $staff = Staff::lookup(array('username'=>$data['staffUserName']));
                if ($staff <= 0)
                    return $this->exerr(404, __("Agent not found"));

                $data['staffId']= $staff -> getId();
                $data['poster'] = $staff;
                $data['reply-to'] = 'all'; 
                $alert = strcasecmp('none', $data['reply-to']);
                $response = $ticket->postReply($data , $errors , $alert );
            } else { 
                if (!$data['userEmail'])
                    return $this->exerr(400, __("Missing user email parameter"));

                $thisclient=TicketUser::lookupByEmail($data['userEmail']);
                if ($thisclient <= 0)
                    return $this->exerr(404, __("User not found"));

                if(!$ticket->checkUserAccess($thisclient)) //double check perm again!
                    return $this->exerr(401,__('Access Denied. User and ticket did not match.'));
                  
                $data['userId'] = $thisclient->getId();
                $data['poster'] = (string) $thisclient->getName();
                $data['message'] = $data['response'];
                
                $response = $ticket->postMessage($data , $errors);
          }

          if(!$response)
            return $this->exerr(500, __("Unable to reply to this ticket: unknown error"));

            $location_base = '/api/tickets/';
            $result = array( 'status' => 'Ok', 'msg' => 'reply posted successfully');
            $result_code=200;
            $this->response($result_code, json_encode($result ),$contentType="application/json");
        }
        catch ( Throwable $e){
          $msg = $e-> getMessage();
          $result = array('tickets'=> array() ,'status' => 'FAILURE', 'msg' => $msg);
          $this->response(500, json_encode($result),$contentType="application/json");
        }
    }

    function getEntryAttachment() {
        try{
              if(!($key=$this->requireApiKey()))
                return $this->exerr(401, __('API key not authorized'));

              $ticket_entry_id = $_REQUEST['entry_id'];
              $ticket_number = $_REQUEST['ticket_id'];
              
              if (!($ticket_number))
                return $this->exerr(400, __('Missing ticket id parameter'));

              if (!($ticket_entry_id))
                return $this->exerr(400, __('Missing ticket entry id parameter'));

              # Checks for valid ticket number
              if (!is_numeric($ticket_number))
                return $this->response(422, __('Invalid ticket number'));

              # Checks for existing ticket with that number
              $id = Ticket::getIdByNumber($ticket_number);
              if ($id <= 0)
                return $this->response(404, __('Ticket not found'));

              $ticket=Ticket::lookup($id);
              $ctr=0;
              foreach (AttachmentFile::objects()->filter(array(
                'attachments__thread_entry__id' => $ticket_entry_id
              )) as $file) {
                $ctr += 1;
                $urls['attachments'.$ctr] = array(
                      'download_url' => $file->getExternalDownloadUrl(['type' => 'H']).'&auth='.$ticket->getuauthLink(),
                      'filetype' => $file->type,
                      'filename' => $file->name,
                    );
                  }
                  $urls['count'] = $ctr;
                  $result_code=200;
                  $this->response($result_code, JsonDataEncoder::encode($urls),$contentType="application/json");
           }
           catch ( Throwable $e){
               $msg = $e-> getMessage();
               $result = array('ticket'=> array() ,'status_code' => 'FAILURE', 'status_msg' => $msg);
               $this->response(500, json_encode($result),$contentType="application/json");
           }
    }
    // End of: Additional from IPI


    /* private helper functions */

    function createTicket($data) {

        # Pull off some meta-data
        $alert       = (bool) (isset($data['alert'])       ? $data['alert']       : true);
        $autorespond = (bool) (isset($data['autorespond']) ? $data['autorespond'] : true);

        # Assign default value to source if not defined, or defined as NULL
        $data['source'] = isset($data['source']) ? $data['source'] : 'API';

        # Create the ticket with the data (attempt to anyway)
        $errors = array();

        $ticket = Ticket::create($data, $errors, $data['source'], $autorespond, $alert);
        # Return errors (?)
        if (count($errors)) {
            if(isset($errors['errno']) && $errors['errno'] == 403)
                return $this->exerr(403, __('Ticket denied'));
            else
                return $this->exerr(
                        400,
                        __("Unable to create new ticket: validation errors").":\n"
                        .Format::array_implode(": ", "\n", $errors)
                        );
        } elseif (!$ticket) {
            return $this->exerr(500, __("Unable to create new ticket: unknown error"));
        }

        return $ticket;
    }

    function processEmail($data=false) {

        if (!$data)
            $data = $this->getEmailRequest();

        $seen = false;
        if (($entry = ThreadEntry::lookupByEmailHeaders($data, $seen))
            && ($message = $entry->postEmail($data))
        ) {
            if ($message instanceof ThreadEntry) {
                return $message->getThread()->getObject();
            }
            else if ($seen) {
                // Email has been processed previously
                return $entry->getThread()->getObject();
            }
        }

        // Allow continuation of thread without initial message or note
        elseif (($thread = Thread::lookupByEmailHeaders($data))
            && ($message = $thread->postEmail($data))
        ) {
            return $thread->getObject();
        }

        // All emails which do not appear to be part of an existing thread
        // will always create new "Tickets". All other objects will need to
        // be created via the web interface or the API
        return $this->createTicket($data);
    }

}

//Local email piping controller - no API key required!
class PipeApiController extends TicketApiController {

    //Overwrite grandparent's (ApiController) response method.
    function response($code, $resp) {

        //Use postfix exit codes - instead of HTTP
        switch($code) {
            case 201: //Success
                $exitcode = 0;
                break;
            case 400:
                $exitcode = 66;
                break;
            case 401: /* permission denied */
            case 403:
                $exitcode = 77;
                break;
            case 415:
            case 416:
            case 417:
            case 501:
                $exitcode = 65;
                break;
            case 503:
                $exitcode = 69;
                break;
            case 500: //Server error.
            default: //Temp (unknown) failure - retry
                $exitcode = 75;
        }

        //echo "$code ($exitcode):$resp";
        //We're simply exiting - MTA will take care of the rest based on exit code!
        exit($exitcode);
    }

    function  process() {
        $pipe = new PipeApiController();
        if(($ticket=$pipe->processEmail()))
           return $pipe->response(201, $ticket->getNumber());

        return $pipe->exerr(416, __('Request failed - retry again!'));
    }
}

?>
