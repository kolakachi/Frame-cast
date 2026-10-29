// Classic or new editor (owner, 2026-10-29). The server decides who may choose (staff until it opens) and the
// default (new accounts after the opening deploy); this saves a person's own pick, which always wins.
import api from "../services/api";
import { useAuthStore } from "../stores/auth";

export async function chooseEditor(which) {
  const auth = useAuthStore();
  if (!auth.user?.editor_new_available) return;
  auth.user = { ...auth.user, editor: which, preferences: { ...(auth.user.preferences ?? {}), editor: which } };
  auth.persist();
  try {
    await api.patch("/me", { preferences: { editor: which } });
  } catch {
    // The pick still holds in this browser; the next /me refresh settles it.
  }
}
