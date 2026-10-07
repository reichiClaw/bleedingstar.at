<section class="wrap section narrow">
  <h1>Admin</h1>
  <?php if (!empty($error)): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="/admin/login" class="form">
    <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
    <label>E-Mail <input name="email" type="email" required autocomplete="username"></label>
    <label>Passwort <input name="password" type="password" required autocomplete="current-password"></label>
    <button class="btn btn-accent" type="submit">Anmelden</button>
  </form>
</section>
