<?php

// REQ-INPUT-03
test('corpo maior que 1 MiB retorna 413', function () {
    /** @var \Tests\TestCase $this */
    $this->postJson('/api/veiculos/debitos', [
        'placa' => str_repeat('x', 1_048_577),
    ])->assertStatus(413)
      ->assertExactJson(['error' => 'payload_too_large']);
});

// REQ-INPUT-04
test('campo extra retorna 422 com unexpected_fields', function () {
    /** @var \Tests\TestCase $this */
    $this->postJson('/api/veiculos/debitos', [
        'placa' => 'ABC1234',
        'extra' => 'valor',
    ])->assertStatus(422)
      ->assertExactJson(['error' => 'unexpected_fields', 'fields' => ['extra']]);
});

test('multiplos campos extras sao listados todos no fields', function () {
    /** @var \Tests\TestCase $this */
    $this->postJson('/api/veiculos/debitos', [
        'placa' => 'ABC1234',
        'foo'   => 1,
        'bar'   => 2,
    ])->assertStatus(422)
      ->assertJsonPath('error', 'unexpected_fields')
      ->assertJsonCount(2, 'fields');
});
