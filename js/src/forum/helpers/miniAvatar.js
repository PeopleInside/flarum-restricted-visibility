/**
 * Mini avatar per utenti "leggeri" ({ id, name, avatarUrl }), come quelli che l'API restituisce in
 * `restrictedUsers`, oppure per veri modelli User.
 *
 * Non viene salvata nessuna copia dell'avatar: l'URL arriva ogni volta dall'API, quindi se l'utente
 * cambia foto profilo l'immagine si aggiorna alla prossima lettura del dato.
 */
function normalize(user) {
  if (user && typeof user.displayName === 'function') {
    return { name: user.displayName(), avatarUrl: user.avatarUrl?.() || null };
  }
  return { name: (user && user.name) || '?', avatarUrl: (user && user.avatarUrl) || null };
}

function colorFor(name) {
  let h = 0;
  for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) % 360;
  return 'hsl(' + h + ', 45%, 50%)';
}

export default function miniAvatar(user) {
  const { name, avatarUrl } = normalize(user);

  if (avatarUrl) {
    return <img className="PeopleInside-avatar" src={avatarUrl} alt="" loading="lazy" />;
  }

  return (
    <span className="PeopleInside-avatar PeopleInside-avatar--letter" style={{ background: colorFor(name) }} aria-hidden="true">
      {(name.charAt(0) || '?').toUpperCase()}
    </span>
  );
}
