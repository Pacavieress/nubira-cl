window.sumarVisitaYEntrar = function(id) {
  if (window.__redireccionando) return false;
  window.__redireccionando = true;
  fetch('/app/sumar_visita.php?id=' + id)
    .then(res => res.json())
    .then(data => {
      if (data.ok) {
        window.location = '/detalle-servicio/' + id;
      } else if (data.error === 'No cuenta visitas del dueño') {
        alert('No puedes sumar visitas a tu propia publicación.');
        window.location = '/detalle-servicio/' + id;
      } else {
        window.location = '/detalle-servicio/' + id;
      }
    })
    .catch(() => {
      window.location = '/detalle-servicio/' + id;
    });
  return false;
};
