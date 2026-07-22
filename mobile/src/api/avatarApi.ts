import { Platform } from 'react-native';
import { apiClient } from './client';

export interface AvatarResponse {
  avatar_url: string | null;
}

// Guess a sane filename/mime from the picked asset URI.
function inferFile(uri: string): { name: string; type: string } {
  const ext = (uri.split('.').pop() ?? 'jpg').toLowerCase().split('?')[0].split('#')[0];
  const safeExt = ['jpg', 'jpeg', 'png', 'webp'].includes(ext) ? ext : 'jpg';
  const type = safeExt === 'jpg' || safeExt === 'jpeg' ? 'image/jpeg' : `image/${safeExt}`;
  const name = `avatar.${safeExt === 'jpeg' ? 'jpg' : safeExt}`;
  return { name, type };
}

export const avatarApi = {
  // POST /me/avatar — multipart upload of the selected image.
  upload: async (uri: string): Promise<AvatarResponse> => {
    const { name, type } = inferFile(uri);
    const form = new FormData();

    if (Platform.OS === 'web') {
      // On web the picked URI is a blob:/data: URL. It MUST be turned into a
      // real Blob and appended with a filename — appending an RN
      // { uri, name, type } object here stringifies to "[object Object]",
      // which Laravel rejects with "The avatar field must be a file."
      const res = await fetch(uri);
      const raw = await res.blob();
      const typed = raw.type ? raw : new Blob([raw], { type });
      // 3-arg append (value, filename) sends a proper multipart file part.
      (form as any).append('avatar', typed, name);
    } else {
      // Native (iOS/Android): RN FormData accepts the file descriptor object.
      form.append('avatar', { uri, name, type } as any);
    }

    return apiClient.request<AvatarResponse>('/me/avatar', { method: 'POST', body: form });
  },

  remove: () => apiClient.request<AvatarResponse>('/me/avatar', { method: 'DELETE' }),
};
