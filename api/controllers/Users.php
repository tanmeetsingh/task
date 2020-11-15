<?php
use RedBeanPHP\R;

class Users extends BaseController {

  public function getAllUsers($request, $response) {
    $status = $this->secureRoute($request, $response, SecurityLevel::USER);
    if ($status !== 200) {
      return $this->jsonResponse($response, $status);
    }

    $this->apiJson->setSuccess();
    $data = $this->getAllUsersCleaned($request);
    $this->apiJson->addData($data);

    return $this->jsonResponse($response);
  }

  public function getUser($request, $response, $args) {
    $status = $this->secureRoute($request, $response, SecurityLevel::USER);
    if ($status !== 200) {
      return $this->jsonResponse($response, $status);
    }

    $id = (int)$args['id'];

    $userIds = $this->getUserIdsByBoardAccess(Auth::GetUserId($request));
    $user = R::load('user', $id);

    if ($user->id === 0) {
      $this->logger->error('Attempt to load user ' . $id .
        ' failed.');
      $this->apiJson->addAlert('error', $this->strings->api_noUserId .
        $id . '.');

      return $this->jsonResponse($response);
    }

    if (!in_array($id, $userIds)) {
      $this->apiJson->addAlert('error', $this->strings->api_accessRestricted);

      return $this->jsonResponse($response, 403);
    }

    $this->apiJson->setSuccess();
    $this->apiJson->addData($this->cleanUser($user));

    return $this->jsonResponse($response);
  }

  public function addUser($request, $response) {
    $status = $this->secureRoute($request, $response, SecurityLevel::ADMIN);
    if ($status !== 200) {
      return $this->jsonResponse($response, $status);
    }

    $data = json_decode($request->getBody());
    $user = R::dispense('user');

    if (isset($data->username)) {
      if ($this->checkUsernameExists($data)) {
        return $this->jsonResponse($response);
      }
    }

    if (isset($data->password) &&
      $data->password === $data->password_verify) {
      $data->password_hash =
        password_hash($data->password, PASSWORD_BCRYPT);
      unset($data->password);
      unset($data->password_verify);
    }

    if (!BeanLoader::LoadUser($user, json_encode($data))) {
      $user->id = -1;
    }

    if ($user->id === -1) {
      $this->logger->error('Add User: ', [$user]);
      $this->apiJson->addAlert('error', $this->strings->api_userError);

      return $this->jsonResponse($response);
    }

    $opts = R::dispense('useroption');
    $opts->new_tasks_at_bottom = true;
    $opts->show_animations = true;
    $opts->show_assignee = true;
    $opts->multiple_tasks_per_row = false;
    $opts->language = 'en';
    R::store($opts);

    $user->user_option_id = $opts->id;
    R::store($user);

    if (isset($data->default_board_id)) {
      $data->boardAccess[] = $data->default_board_id;
    }

    $data->id = $user->id;
    $this->updateBoardAccess($data, $request);

    $actor = R::load('user', Auth::GetUserId($request));
    $this->dbLogger->logChange($actor->id,
      $actor->username . ' added user ' . $user->username . '.',
      '', json_encode($user), 'user', $user->id);

    $this->apiJson->setSuccess();
    $this->apiJson->addAlert('success', $this->strings->api_userAdded .
      '(' . $user->username . ').');
    $this->apiJson->addData($this->getAllUsersCleaned($request));

    return $this->jsonResponse($response);
  }

  public function updateUser($request, $response, $args) {
    $status = $this->secureRoute($request, $response, SecurityLevel::USER);
    if ($status !== 200) {
      return $this->jsonResponse($response, $status);
    }

    $data = json_decode($request->getBody());
    $user = R::load('user', (int)$args['id']);

    if (!property_exists($data, 'id')) {
      $this->logger->error('Update User: ', [$user, $data]);
      $this->apiJson->addAlert('error', $this->strings->api_userUpdateError);

      return $this->jsonResponse($response);
    }

    $update = R::load('user', $data->id);
    $actor = R::load('user', Auth::GetUserId($request));

    if (!$this->checkUserAccess($actor, $user)) {
      return $this->jsonResponse($response, 403);
    }

    $data->password_hash = $user->password_hash;

    if (isset($data->new_password) && isset($data->old_password)) {
      if (!$this->verifyPassword($data, $user)) {
        $this->logger->error('Update User: ', [$user, $update]);
        return $this->jsonResponse($response);
      }

      $data->password_hash =
        password_hash($data->new_password, PASSWORD_BCRYPT);
      unset($data->new_password);
      unset($data->old_password);
    }

    $data->active_token = $user->active_token;

    if (isset($data->password) && $data->password !== '') {
      $data->password_hash =
        password_hash($data->password, PASSWORD_BCRYPT);
      unset($data->password);
    }

    BeanLoader::LoadUser($update, json_encode($data));

    if ((int)$user->id !== (int)$update->id) {
      $this->logger->error('Update User: ', [$user, $update]);
      $this->apiJson->addAlert('error', $this->strings->api_userUpdateError);

      return $this->jsonResponse($response);
    }

    if ($user->username !== $update->username) {
      if ($this->checkUsernameExists($update)) {
        return $this->jsonResponse($response);
      }
    }

    $this->updateDefaultBoardId($data, $user, $update);

    $this->updateBoardAccess($data, $request);
    R::store($update);

    $this->dbLogger->logChange($actor->id,
      $actor->username . ' updated user ' . $update->username,
      json_encode($user), json_encode($update),
      'user', $update->id);

    $this->apiJson->setSuccess();
    $this->apiJson->addAlert('success', $this->strings->api_userUpdated .
      '(' . $update->username . ').');
    $this->apiJson->addData(json_encode($this->cleanUser($update)));

    return $this->jsonResponse($response);
  }

  public function updateUserOptions($request, $response, $args) {
    $status = $this->secureRoute($request, $response, SecurityLevel::USER);
    if ($status !== 200) {
      return $this->jsonResponse($response, $status);
    }

    $user = R::load('user', (int)$args['id']);
    $actor = R::load('user', Auth::GetUserId($request));

    if ($actor->id !== $user->id) {
      $this->apiJson->addAlert('error', $this->strings->api_accessRestricted);

      return $this->jsonResponse($response, 403);
    }

    $data = $request->getBody();

    $userOpts = R::load('useroption', $user->user_option_id);
    $update = R::load('useroption', json_decode($data)->id);

    if (!BeanLoader::LoadUserOption($update, $data)) {
      $update->id = -1;
    }

    if ($userOpts->id !== $update->id) {
      $this->logger->error('Update User Options: ',
        [$userOpts, $update]);
      $this->apiJson->addAlert('error', $this->strings->api_userOptError);

      return $this->jsonResponse($response);
    }

    R::store($update);

    $this->dbLogger->logChange($actor->id,
      $actor->username . ' updated user options',
      json_encode($userOpts), json_encode($update),
      'user_option', $update->id);

    $this->apiJson->setSuccess();
    $this->apiJson->addAlert('success', $this->strings->api_userOptUpdated);
    $this->apiJson->addData(json_encode($update));
    $this->apiJson->addData(json_encode($this->cleanUser($user)));

    return $this->jsonResponse($response);
  }

  public function toggleCollapsed($request, $response, $args) {
    $status = $this->secureRoute($request, $response, SecurityLevel::USER);
    if ($status !== 200) {
      return $this->jsonResponse($response, $status);
    }

    $user = R::load('user', (int)$args['id']);
    $actor = R::load('user', Auth::GetUserId($request));

    if ($actor->id !== $user->id) {
      $this->apiJson->addAlert('error', $this->strings->api_accessRestricted);

      return $this->jsonResponse($response, 403);
    }

    $data = json_decode($request->getBody());
    $collapsed = R::findOne('collapsed', ' user_id = ? AND column_id = ? ',
      [ $user->id, $data->id ]);

    $makeNew = true;
    if (!is_null($collapsed)) {
      R::trash($collapsed);
      $makeNew = false;
    }

    if ($makeNew) {
      $collapsed = R::dispense('collapsed');
      $collapsed->user_id = $user->id;
      $collapsed->column_id = $data->id;

      R::store($collapsed);
    }

    $allCollapsed = R::find('collapsed', ' user_id = ? ', [ $user->id ]);

    $this->apiJson->setSuccess();
    $this->apiJson->addData(R::exportAll($allCollapsed));

    return $this->jsonResponse($response);
  }

  public function removeUser($request, $response, $args) {
    $status = $this->secureRoute($request, $response, SecurityLevel::ADMIN);
    if ($status !== 200) {
      return $this->jsonResponse($response, $status);
    }

    $id = (int)$args['id'];
    $user = R::load('user', $id);

    if ((int)$user->id !== $id) {
      $this->logger->error('Remove User: ', [$user]);
      $this->apiJson->addAlert('error', $this->strings->api_userRemoveError .
        $id . '.');

      return $this->jsonResponse($response);
    }

    $before = $user;
    R::trash($user);

    $actor = R::load('user', Auth::GetUserId($request));
    $this->dbLogger->logChange($actor->id,
      $actor->username . ' removed user ' . $before->username,
      json_encode($before), '', 'user', $id);

    $this->apiJson->setSuccess();
    $this->apiJson->addAlert('success', $this->strings->api_userRemoved .
      '(' . $before->username . ').');
    $this->apiJson->addData($this->getAllUsersCleaned($request));

    return $this->jsonResponse($response);
  }

  private function updateBoardAccess(&$userData, $request) {
    $boardIds = $this->getBoardIdsByAccess($userData->id);

    if (isset($userData->boardAccess)) {
      $user = R::load('user', $userData->id);

      foreach ($userData->boardAccess as $boardId) {
        if (!in_array($boardId, $boardIds)) {
          $this->addUserToBoard((int)$boardId, $user, $request);
        }
      }

      if (count(array_diff($userData->boardAccess, $boardIds))) {
        foreach ($boardIds as $removeId) {
          if (!in_array($removeId, $userData->boardAccess)) {
            $this->removeUserFromBoard($removeId, $user);
          }
        }
      }

      R::store($user);
      unset($userData->boardAccess);
    }
  }

  private function addUserToBoard($boardId, $user, $request) {
    if ($boardId > 0 &&
      !Auth::HasBoardAccess($request, $boardId, $user->id)) {
      $board = R::load('board', $boardId);
      $board->sharedUserList[] = $user;
      R::store($board);
    }
  }

  private function removeUserFromBoard($boardId, $user) {
    if ($boardId > 0) {
      $board = R::load('board', $boardId);
      unset($board->sharedUserList[$user->id]);
      R::store($board);
    }
  }

  private function getAllUsersCleaned($request) {
    $userBeans = R::findAll('user');
    $userId = Auth::GetUserId($request);

    $userIds = $this->getUserIdsByBoardAccess(Auth::GetUserId($request));

    // If a user has no board access, they should still see themselves
    if (count($userIds) === 0) {
      $userIds[] = $userId;
    }

    $actor = R::load('user', $userId);
    $isAdmin = ((int)$actor->security_level === SecurityLevel::ADMIN);

    $data = [];
    foreach ($userBeans as $user) {
      if (in_array($user->id, $userIds) || $isAdmin) {
        $data[] = $this->cleanUser($user);
      }
    }

    return $data;
  }

  private function getBoardIdsByAccess($userId) {
    $boardIds = [];

    $boards = R::getAll('SELECT board_id FROM board_user ' .
      'WHERE user_id = :user_id',
      [':user_id' => $userId]);

    foreach ($boards as $board) {
      $boardIds[] = (int)$board['board_id'];
    }

    return $boardIds;
  }

  private function getUserIdsByBoardAccess($userId) {
    $userIds = [];
    $boardIds = $this->getBoardIdsByAccess($userId);

    foreach ($boardIds as $id) {
      $board = R::load('board', $id);

      foreach ($board->sharedUserList as $user) {
        if (!in_array((int) $user->id, $userIds)) {
          $userIds[] = (int) $user->id;
        }
      }
    }

    return $userIds;
  }

  private function cleanUser($user) {
    unset($user->password_hash);
    unset($user->active_token);

    $this->setBoardAccess($user);

    return $user;
  }

  private function setBoardAccess(&$user) {
    $user->board_access = [];
    $boards = RedBeanPHP\R::getAll('select bu.board_id, bu.user_id from ' .
      'board_user bu join board b on b.id = bu.board_id');

    foreach ($boards as $item) {
      if ((int)$user->id === (int)$item['user_id']) {
        $user->board_access[] = (int)$item['board_id'];
      }
    }
  }

  private function checkUsernameExists($data) {
    $existing = R::findOne('user', 'username = ?', [ $data->username ]);

    if ($existing) {
      $this->apiJson->addAlert('error', $this->strings->api_usernameExists);
      return true;
    }

    return false;
  }

  private function checkUserAccess($actor, $user) {
    if ((int)$actor->id !== (int)$user->id) {
      if ((int)$actor->security_level === SecurityLevel::ADMIN) {
        return true;
      }

      $this->apiJson->addAlert('error', $this->strings->api_accessRestricted);
      return false;
    }

    return true;
  }

  private function verifyPassword($data, $user) {
    if (!password_verify($data->old_password, $user->password_hash)) {
      $this->apiJson->addAlert('error', $this->strings->api_userBadPword);
      return false;
    }

    return true;
  }

  private function updateDefaultBoardId(&$data, $user, $update) {
    if ($user->default_board_id === $update->default_board_id ||
      (int)$update->default_board_id === 0) {
      return;
    }

    if (isset($data->boardAccess) &&
      !in_array($data->default_board_id, $data->boardAccess)) {
      $data->boardAccess[] = $data->default_board_id;
    }
  }
}

