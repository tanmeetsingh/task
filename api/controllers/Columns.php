<?php
use RedBeanPHP\R;

class Columns extends BaseController {

  public function getColumn($request, $response, $args) {
    $status = $this->secureRoute($request, $response, SecurityLevel::USER);
    if ($status !== 200) {
      return $this->jsonResponse($response, $status);
    }

    $column = R::load('column', (int)$args['id']);

    if ((int)$column->id === 0) {
      $this->logger->error('Attempt to load column ' .
        $args['id'] . ' failed.');
      $this->apiJson->addAlert('error', $this->strings->api_noColumnId .
        $args['id'] . '.');

      return $this->jsonResponse($response);
    }

    if (!$this->checkBoardAccess($column->board_id, $request)) {
      return $this->jsonResponse($response, 403);
    }

    $this->apiJson->setSuccess();
    $this->apiJson->addData(R::exportAll($column));

    return $this->jsonResponse($response);
  }

  public function addColumn($request, $response) {
    $status = $this->secureRoute($request, $response, SecurityLevel::BOARD_ADMIN);
    if ($status !== 200) {
      return $this->jsonResponse($response, $status);
    }

    $column = R::dispense('column');
    if (!BeanLoader::LoadColumn($column, $request->getBody())) {
      $column->board_id = 0;
    }

    $board = R::load('board', $column->board_id);

    if ((int)$board->id === 0) {
      $this->logger->error('Add Column: ', [$column]);
      $this->apiJson->addAlert('error', $this->strings->api_columnError);

      return $this->jsonResponse($response);
    }

    if (!$this->checkBoardAccess($column->board_id, $request)) {
      return $this->jsonResponse($response, 403);
    }

    R::store($column);

    $actor = R::load('user', Auth::GetUserId($request));
    $this->dbLogger->logChange($actor->id,
      $actor->username . ' added column ' . $column->name . '.',
      '', json_encode($column), 'column', $column->id);

    $this->apiJson->setSuccess();
    $this->apiJson->addAlert('success', $this->strings->api_columnAdded .
      '(' .  $column->name . ').');

    return $this->jsonResponse($response);
  }

  public function updateColumn($request, $response, $args) {
    $status = $this->secureRoute($request, $response, SecurityLevel::BOARD_ADMIN);
    if ($status !== 200) {
      return $this->jsonResponse($response, $status);
    }

    $data = json_decode($request->getBody());

    if (is_null($args) || !array_key_exists('id', $args)) {
      $this->logger->error('Update Column: ', [$data]);
      $this->apiJson->addAlert('error', $this->strings->api_columnUpdateError);

      return $this->jsonResponse($response);
    }

    $column = R::load('column', (int)$args['id']);

    $update = R::dispense('column');
    $update->id = BeanLoader::LoadColumn($update, $request->getBody())
      ? $column->id
      : 0;

    if ($column->id === 0 || (int)$column->id !== (int)$update->id) {
      $this->logger->error('Update Column: ', [$column, $update]);
      $this->apiJson->addAlert('error', $this->strings->api_columnUpdateError);

      return $this->jsonResponse($response);
    }

    if (!$this->checkBoardAccess($column->board_id, $request)) {
      return $this->jsonResponse($response, 403);
    }

    R::store($update);

    $actor = R::load('user', Auth::GetUserId($request));
    $this->dbLogger->logChange($actor->id,
      $actor->username . ' updated column ' . $update->name,
      json_encode($column), json_encode($update),
      'column', $update->id);

    $this->apiJson->setSuccess();
    $this->apiJson->addAlert('success', $this->strings->api_columnUpdated .
      '(' .  $update->name . ').');
    $this->apiJson->addData(R::exportAll($update));
    $this->apiJson->addData(R::exportAll(R::load('board', $update->board_id)));

    return $this->jsonResponse($response);
  }

  public function removeColumn($request, $response, $args) {
    $status = $this->secureRoute($request, $response, SecurityLevel::BOARD_ADMIN);
    if ($status !== 200) {
      return $this->jsonResponse($response, $status);
    }

    $id = (int)$args['id'];
    $column = R::load('column', $id);

    if ((int)$column->id !== $id) {
      $this->logger->error('Remove Column: ', [$column]);
      $this->apiJson->addAlert('error', $this->strings->api_columnRemoveError .
        $id . '.');

      return $this->jsonResponse($response);
    }

    if (!$this->checkBoardAccess($column->board_id, $request)) {
      return $this->jsonResponse($response, 403);
    }

    $before = $column;
    R::trash($column);

    $actor = R::load('user', Auth::GetUserId($request));
    $this->dbLogger->logChange($actor->id,
      $actor->username . ' removed column ' . $before->name,
      json_encode($before), '', 'column', $id);

    $this->apiJson->setSuccess();
    $this->apiJson->addAlert('success', $this->strings->api_columnRemoved .
      '(' . $before->name . ').');

    return $this->jsonResponse($response);
  }
}

