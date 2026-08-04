namespace AIWordPressManager.Application.Abstractions;

public interface IDialogService
{
    Task ShowInformationAsync(string title, string message, CancellationToken cancellationToken = default);

    Task ShowErrorAsync(string title, string message, CancellationToken cancellationToken = default);

    Task<bool> ConfirmAsync(string title, string message, CancellationToken cancellationToken = default);
}
