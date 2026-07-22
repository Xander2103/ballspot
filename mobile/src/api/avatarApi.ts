import { apiClient } from './client';

export interface AvatarResponse {
  avatar_url: string | null;
}

// Guess a sane filename/mime from the picked asset URI.
function inferFile(uri: string): { name: string; type: string } {
  const ext = (uri.split('.').pop() ?? 'jpg').toLowerCase().split('?')[0];
  const safeExt = ['jpg', 'jpeg', 'png', 'webp'].includes(ext) ? ext : 'jpg';
  const type = safeExt === 'jpg' ? 'image/jpeg' : `image/${safeExt}`;
  return { name: `avatar.${safeExt}`, type };
}

export const avatarApi = {
  // POST /me/avatar — multipart upload of the selected image.
  upload: (uri: string) => {
    const { name, type } = inferFile(uri);
    const form = new FormData();
    // React Native FormData file shape.
    form.append('avatar', { uri, name, type } as any);
    return apiClient.request<AvatarResponse>('/me/avatar', { method: 'POST', body: form });
  },

  remove: () => apiClient.request<AvatarResponse>('/me/avatar', { method: 'DELETE' }),
};
